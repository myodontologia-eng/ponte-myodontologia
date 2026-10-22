"""Private Telegram scheduling with a constrained conversational planner."""
import asyncio
import hashlib
import hmac
import logging
import os
import re
import secrets
import time
import unicodedata
from datetime import datetime, timedelta
from zoneinfo import ZoneInfo

import httpx
from fastapi import APIRouter, Header, HTTPException, Request
from pydantic import BaseModel, Field
from starlette.concurrency import run_in_threadpool
from calendar_service import CalendarError, list_events, update_event_status
from myla_service import create_checked, slots, reschedule_checked
from telegram_ai import plan as ai_plan, forget as ai_forget

router = APIRouter(prefix='/telegram', tags=['Telegram'])
TZ = ZoneInfo('America/Sao_Paulo')
LOCK = asyncio.Lock()
SESSIONS = {}
SEEN = {}
MENU = {'keyboard': [[{'text': 'Agenda de hoje'}, {'text': 'Horários livres'}],
                     [{'text': 'Novo agendamento'}, {'text': 'Alterar agendamento'}],
                     [{'text': 'Aguardando'}],
                     [{'text': 'Cancelar operação'}]],
        'resize_keyboard': True}


def normalized(text):
    return ''.join(c for c in unicodedata.normalize('NFD', text.lower())
                   if unicodedata.category(c) != 'Mn').strip()


def owner():
    return os.getenv('DR_MARCIO_CHAT_ID', '').strip()


def allowed(uid):
    ids = {v.strip() for v in os.getenv('TELEGRAM_SECRETARIA_IDS', '').split(',') if v.strip()}
    return str(uid) == owner() or str(uid) in ids


def webhook_secret():
    key = os.getenv('INTERNAL_API_TOKEN', '')
    if not key:
        raise HTTPException(503, 'Token interno não configurado')
    return hmac.new(key.encode(), b'myodontologia-telegram-webhook-v1', hashlib.sha256).hexdigest()


async def api(method, payload):
    token = os.getenv('TELEGRAM_BOT_TOKEN', '').strip()
    if not token:
        raise HTTPException(503, 'Telegram não configurado')
    try:
        async with httpx.AsyncClient(timeout=15) as client:
            response = await client.post('https://api.telegram.org/bot' + token + '/' + method, json=payload)
        data = response.json()
        if response.status_code != 200 or data.get('ok') is not True:
            raise ValueError('rejected')
        return data.get('result')
    except Exception:
        # HTTP exception messages can contain the bot token.
        logging.getLogger(__name__).warning('Telegram operation unavailable: %s', method)
        raise HTTPException(502, 'Telegram temporariamente indisponível') from None


async def audit_message(chat, message_id, direction, text, actor='', display_name=''):
    token = os.getenv('INTERNAL_API_TOKEN', '')
    try:
        async with httpx.AsyncClient(timeout=8) as client:
            response = await client.post('https://sistema.myodontologia.com/wp-json/myod/v1/telegram/messages',
                headers={'Authorization': 'Bearer ' + token},
                json={'chat_id': str(chat), 'message_id': str(message_id), 'direction': direction,
                      'text': text[:4000], 'actor': actor, 'display_name': display_name[:150]})
        result = response.json()
        if response.status_code != 200 or result.get('ok') is not True:
            raise ValueError()
        return result
    except Exception:
        logging.getLogger(__name__).warning('Telegram inbox unavailable')
        return None


async def send(chat, text, markup=None, actor='myla'):
    body = {'chat_id': chat, 'text': text[:4000], 'protect_content': True}
    if markup:
        body['reply_markup'] = markup
    result = await api('sendMessage', body)
    await audit_message(chat, (result or {}).get('message_id') or secrets.token_hex(16), 'out', text, actor)
    return result


def get_day(text, now=None):
    now = now or datetime.now(TZ)
    value = normalized(text)
    if 'depois de amanha' in value:
        return (now + timedelta(days=2)).date()
    if 'amanha' in value:
        return (now + timedelta(days=1)).date()
    if 'hoje' in value:
        return now.date()
    m = re.search(r'\b(\d{1,2})/(\d{1,2})(?:/(\d{4}))?\b', value)
    if m:
        return datetime(int(m[3] or now.year), int(m[2]), int(m[1]), tzinfo=TZ).date()
    return None


def extract(text, draft):
    day = get_day(text)
    if day:
        draft['day'] = day.isoformat()
    value = normalized(text)
    m = re.search(r'(?:\bas\s+|\b)(\d{1,2})(?:h([0-5]\d)?|:([0-5]\d))\b', value)
    if m:
        hour, minute = int(m[1]), int(m[2] or m[3] or 0)
        if hour > 23:
            raise ValueError('hora')
        draft['clock'] = f'{hour:02d}:{minute:02d}'
    m = re.search(r'\b(15|30|45|60|90|120)\s*(?:minutos|min)\b', value)
    if m:
        draft['duration'] = int(m[1])
    m = re.search(r'(?:marque|marcar|agende|agendar)\s+(?:para\s+)?(.+?)(?=\s+(?:hoje|amanh[ãa]|dia\s+\d|\d{1,2}/|[àa]s\s+\d)|$)', text, re.I)
    if m and normalized(m[1]) not in ('consulta', 'uma consulta', 'um horario'):
        draft['name'] = m[1].strip()[:150]


async def prompt(chat, state):
    d = state['draft']
    for key, question in [('name', 'Qual é o nome do paciente?'),
                          ('day', 'Para qual dia? Pode escrever amanhã ou 23/10/2026.'),
                          ('clock', 'Qual horário? Exemplo: 17h ou 17:30.'),
                          ('duration', 'Qual duração: 15, 30, 45, 60, 90 ou 120 minutos?')]:
        if not d.get(key):
            state['waiting'] = key
            await send(chat, question)
            return
    start = datetime.fromisoformat(d['day'] + 'T' + d['clock']).replace(tzinfo=TZ)
    end = start + timedelta(minutes=d['duration'])
    opens, closes = (7, 13) if start.weekday() == 5 else (16, 20)
    if start <= datetime.now(TZ) or start.weekday() == 6 or start.hour < opens or end.date() != start.date() or end.hour > closes or (end.hour == closes and end.minute):
        d.pop('clock', None)
        state['waiting'] = 'clock'
        await send(chat, 'Escolha um horário futuro dentro do atendimento: segunda a sexta, 16h–20h; sábado, 7h–13h. Para mudar o dia, escreva a nova data e o horário.')
        return
    state['waiting'] = 'confirm'
    # Old preview buttons must never confirm a later edited draft.
    state['nonce'] = secrets.token_hex(16)
    d['start'], d['end'] = start.isoformat(), end.isoformat()
    question = 'Confirma a remarcação?' if state.get('mode') == 'reschedule' else 'Confirma o agendamento?'
    await send(chat, f"{question}\nPaciente: {d['name']}\nDia: {start:%d/%m/%Y}\nHorário: {start:%H:%M}–{end:%H:%M}\nDuração: {d['duration']} minutos",
               {'inline_keyboard': [[{'text': 'Confirmar agendamento', 'callback_data': 'confirm:' + state['nonce']},
                                     {'text': 'Cancelar', 'callback_data': 'cancel:' + state['nonce']}]]})


async def arrival_list(uid):
    """Only today's active timed appointments are eligible for check-in."""
    now = datetime.now(TZ)
    a = now.replace(hour=0, minute=0, second=0, microsecond=0)
    events = await run_in_threadpool(list_events, time_min=a.isoformat(), time_max=(a + timedelta(days=1)).isoformat())
    eligible = []
    for event in events:
        status = ((event.get('extendedProperties') or {}).get('private') or {}).get('status') or 'Agendado'
        if event.get('status') == 'cancelled' or status not in ('Agendado', 'Remarcado', 'Atendimento atrasado'):
            continue
        value = event.get('start', {}).get('dateTime')
        if not value or not event.get('id') or not event.get('etag'):
            continue
        start = datetime.fromisoformat(value.replace('Z', '+00:00')).astimezone(TZ)
        if start.date() == now.date():
            eligible.append(event)
    if not eligible:
        await send(uid, 'Nenhum agendamento de hoje disponível para marcar como Aguardando.', MENU)
        return
    # Telegram callback data contains only a random reference, never patient data.
    nonce = secrets.token_hex(12)
    SESSIONS[str(uid)] = {'mode': 'arrival', 'nonce': nonce, 'events': eligible,
                         'expires': time.monotonic() + 600}
    rows = []
    for index, event in enumerate(eligible[:60]):
        start = datetime.fromisoformat(event['start']['dateTime'].replace('Z', '+00:00')).astimezone(TZ)
        label = start.strftime('%H:%M') + ' — ' + (event.get('summary') or 'Consulta')[:80]
        rows.append([{'text': label, 'callback_data': f'arrival:{nonce}:{index}'}])
    await send(uid, 'Quem chegou? Selecione o agendamento para confirmar Aguardando.', {'inline_keyboard': rows})


async def arrival_callback(uid, data, state):
    parts = data.split(':')
    if len(parts) != 3 or not state or state.get('mode') != 'arrival' or parts[1] != state.get('nonce'):
        await send(uid, 'Esta seleção expirou. Toque em Aguardando novamente.', MENU)
        return
    try:
        index = int(parts[2])
        if index < 0:
            raise ValueError()
        event = state['events'][index]
    except (ValueError, IndexError, KeyError):
        await send(uid, 'Seleção inválida. Toque em Aguardando novamente.', MENU)
        return
    start = datetime.fromisoformat(event['start']['dateTime'].replace('Z', '+00:00')).astimezone(TZ)
    if start.date() != datetime.now(TZ).date():
        SESSIONS.pop(str(uid), None)
        await send(uid, 'Este agendamento não é de hoje. Atualize a agenda.', MENU)
        return
    result = await run_in_threadpool(update_event_status, event_id=event['id'], status='Aguardando',
                                    etag=event['etag'], user='telegram:' + str(uid))
    SESSIONS.pop(str(uid), None)
    notification = result.get('telegram_notification', {})
    delivery = 'Aviso enviado ao Dr. Márcio.' if notification.get('sent') else 'O status foi salvo, mas o aviso não foi confirmado; avise o Dr. Márcio diretamente.'
    await send(uid, 'Aguardando — paciente chegou e espera atendimento.\n' + delivery, MENU)


async def status_list(uid, day):
    a = datetime.combine(day, datetime.min.time(), TZ)
    events = await run_in_threadpool(list_events, time_min=a.isoformat(), time_max=(a + timedelta(days=1)).isoformat())
    events = [e for e in events if e.get('status') != 'cancelled' and e.get('id') and e.get('etag') and e.get('start', {}).get('dateTime')]
    if not events:
        await send(uid, f'Nenhum agendamento em {day:%d/%m/%Y}.', MENU)
        return
    nonce = secrets.token_hex(12)
    SESSIONS[str(uid)] = {'mode': 'status', 'events': events, 'nonce': nonce, 'expires': time.monotonic() + 600}
    rows = []
    for i, event in enumerate(events[:60]):
        at = datetime.fromisoformat(event['start']['dateTime'].replace('Z', '+00:00')).astimezone(TZ)
        rows.append([{'text': at.strftime('%H:%M') + ' — ' + (event.get('summary') or 'Consulta')[:80], 'callback_data': f'choose:{nonce}:{i}'}])
    await send(uid, f'Qual agendamento de {day:%d/%m/%Y} deseja alterar?', {'inline_keyboard': rows})


async def status_callback(uid, data, state):
    parts = data.split(':')
    if not state or state.get('mode') != 'status' or len(parts) < 2 or parts[1] != state.get('nonce'):
        await send(uid, 'Esta seleção expirou. Use Alterar agendamento novamente.', MENU)
        return
    action = parts[0]
    if action == 'choose' and len(parts) == 3:
        try:
            i = int(parts[2])
            if i < 0:
                raise ValueError()
            state['selected'] = state['events'][i]
        except (ValueError, IndexError):
            await send(uid, 'Agendamento inválido. Atualize a lista.', MENU)
            return
        state['nonce'] = secrets.token_hex(12)
        statuses = ['Agendado', 'Aguardando', 'Atendido', 'Faltou', 'Desmarcou', 'Atendimento atrasado', 'Remarcado']
        rows = [[{'text': label, 'callback_data': f"status:{state['nonce']}:{label}"}] for label in statuses]
        await send(uid, 'Agendamento: ' + state['selected'].get('summary', 'Consulta')[:150] + '\nQual é a nova situação?', {'inline_keyboard': rows})
        return
    if not state.get('selected'):
        return
    if action == 'status' and len(parts) == 3:
        status = parts[2]
        if status not in ['Agendado', 'Aguardando', 'Atendido', 'Faltou', 'Desmarcou', 'Atendimento atrasado', 'Remarcado']:
            return
        event = state['selected']
        if status == 'Remarcado':
            state.update(mode='reschedule', draft={'name': event.get('summary') or 'Consulta'}, waiting='day')
            await send(uid, 'Para qual nova data? Informe a data, o horário e a duração. Exemplo: amanhã às 17h por 30 minutos.')
            return
        state.update(target_status=status, waiting='status_confirm', nonce=secrets.token_hex(12))
        at = datetime.fromisoformat(event['start']['dateTime'].replace('Z', '+00:00')).astimezone(TZ)
        await send(uid, f"Confirma alterar para {status}?\n{event.get('summary', 'Consulta')[:150]}\n{at:%d/%m/%Y às %H:%M}",
                   {'inline_keyboard': [[{'text': 'Confirmar alteração', 'callback_data': 'apply:' + state['nonce']},
                                         {'text': 'Cancelar', 'callback_data': 'cancel:' + state['nonce']}]]})
        return
    if action == 'apply' and state.get('waiting') == 'status_confirm':
        event = state['selected']
        result = await run_in_threadpool(update_event_status, event_id=event['id'], status=state['target_status'], etag=event['etag'], user='telegram:' + str(uid))
        SESSIONS.pop(str(uid), None)
        note = 'Aviso enviado ao Dr. Márcio.' if result.get('telegram_notification', {}).get('sent') else 'Aviso no Telegram não confirmado; confira a agenda.'
        await send(uid, 'Situação salva: ' + state['target_status'] + '.\n' + note, MENU)


async def handle(update):
    callback = update.get('callback_query')
    message = (callback or {}).get('message') or update.get('message') or {}
    sender = (callback or message).get('from') or {}
    chat = message.get('chat') or {}
    uid = sender.get('id')
    if chat.get('type') != 'private' or uid != chat.get('id'):
        return
    if not allowed(uid):
        # Do not expose schedule data or automatically grant access.
        await send(uid, f'Acesso à agenda ainda não liberado. Informe ao Dr. Márcio este ID: {uid}.')
        return
    if not update.get('_ai_resolved'):
        incoming = message.get('text') or message.get('caption') or ('[Imagem recebida]' if message.get('photo') else '[Mensagem sem texto]')
        if callback:
            incoming = '[Botão] ' + callback.get('data', '').split(':')[0]
        mid = 'cb:' + callback['id'] if callback else str(message.get('message_id', update.get('update_id', 'unknown')))
        audit = await audit_message(uid, mid, 'in', incoming, 'owner' if str(uid) == owner() else 'secretary',
                                    ' '.join(str(sender.get(k, '')) for k in ('first_name', 'last_name')).strip())
        if audit is None:
            await send(uid, 'Não consegui consultar o painel de conversas. Tente novamente em instantes ou use a agenda no sistema.')
            return
        if audit.get('paused'):
            if callback:
                await api('answerCallbackQuery', {'callback_query_id': callback['id'], 'text': 'Atendimento com o Dr. Márcio.'})
            return
    if callback:
        await api('answerCallbackQuery', {'callback_query_id': callback['id']})
    key = str(uid)
    state = SESSIONS.get(key)
    if state and state['expires'] < time.monotonic():
        SESSIONS.pop(key, None)
        state = None
    if callback:
        if callback.get('data', '').startswith(('choose:', 'status:', 'apply:')):
            await status_callback(uid, callback['data'], state)
            return
        if callback.get('data', '').startswith('arrival:'):
            await arrival_callback(uid, callback['data'], state)
            return
        action, _, nonce = callback.get('data', '').partition(':')
        if not state or nonce != state.get('nonce'):
            await send(uid, 'Esta confirmação expirou. Comece um novo agendamento.', MENU)
            return
        if action == 'cancel':
            SESSIONS.pop(key, None)
            await send(uid, 'Operação cancelada. Nenhuma consulta foi criada.', MENU)
        elif action == 'confirm' and state.get('waiting') == 'confirm':
            d = state['draft']
            if state.get('mode') == 'reschedule':
                event = state['selected']
                await run_in_threadpool(reschedule_checked, event_id=event['id'], start=d['start'], end=d['end'], etag=event['etag'], user='telegram:' + key)
            else:
                await run_in_threadpool(create_checked, summary=d['name'], description='Agendado pela equipe via Telegram.',
                                            start=d['start'], end=d['end'], idempotency_key='telegram-' + nonce, user='telegram:' + key)
            state['waiting'] = 'done'
            await send(uid, 'Remarcação salva na agenda central.' if state.get('mode') == 'reschedule' else 'Agendamento salvo na agenda central.', MENU)
        return
    text = message.get('text', '').strip()[:1000]
    value = normalized(text)
    # Common labels from older keyboards and everyday shorthand are local commands.
    agenda_aliases = {'agenda hoje', 'agenda hj', 'agenda de hj', 'ver agenda',
                      'ver agenda hoje', 'ver agenda de hoje', 'ver agenda hj',
                      'ver a agenda', 'ver a agenda de hoje', 'consultar agenda'}
    if value in agenda_aliases:
        text, value = 'Agenda de hoje', 'agenda de hoje'
    elif value in ('quero agendar', 'quero marcar', 'agendar consulta', 'marcar consulta'):
        text, value = 'Novo agendamento', 'novo agendamento'
    if value in ('/start', '/menu', 'menu', '/cancelar', 'cancelar', 'cancelar operacao'):
        SESSIONS.pop(key, None)
        ai_forget(uid)
        await send(uid, 'Sou a Myla. Consulte a agenda ou faça um agendamento pelos botões. Também pode escrever: Marque Ana amanhã às 17h por 30 minutos.', MENU)
        return
    if message.get('photo') or message.get('voice') or message.get('document'):
        await send(uid, 'Nesta primeira etapa, envie os dados por texto. A leitura de imagens e áudios ainda não está habilitada.')
        return
    if value in ('aguardando', '/aguardando', 'paciente chegou', 'marcar aguardando'):
        await arrival_list(uid)
        return
    if value in ('alterar agendamento', 'alterar status', '/status', 'faltou', 'desmarcou', 'demarcou', 'remarcou', 'remarcar', 'remarcado'):
        SESSIONS[key] = {'mode': 'status_day', 'expires': time.monotonic() + 1200}
        await send(uid, 'De qual dia é o agendamento? Pode escrever hoje, amanhã ou a data.')
        return
    if state and state.get('mode') == 'status_day':
        day = get_day(text)
        if not day:
            await send(uid, 'Informe hoje, amanhã ou uma data como 23/10/2026.')
            return
        await status_list(uid, day)
        return
    if state and state.get('mode') == 'reschedule' and state.get('waiting') != 'done':
        extract(text, state['draft'])
        if state.get('waiting') == 'duration' and value.isdigit() and int(value) in (15, 30, 45, 60, 90, 120):
            state['draft']['duration'] = int(value)
        await prompt(uid, state)
        return
    agenda_day = get_day(text) if value.startswith(('agenda ', 'ver agenda ')) else None
    if value.startswith('ver agenda ') and agenda_day:
        text, value = 'agenda ' + agenda_day.strftime('%d/%m/%Y'), 'agenda ' + agenda_day.strftime('%d/%m/%Y')
    slots_day = state and state.get('mode') == 'slots' and get_day(text)
    direct = (value in ('agenda de hoje', '/agenda', 'horarios livres', 'novo agendamento')
              or value.startswith('/horarios') or bool(agenda_day) or bool(slots_day))
    if text and not update.get('_ai_resolved') and not direct:
        active = state if state and state.get('waiting') != 'done' else None
        result = await ai_plan(uid, text, active)
        if result:
            intent = result['intent']
            if intent == 'chat':
                await send(uid, result['answer'] or 'Como posso ajudar com a agenda?', MENU)
                return
            if intent == 'cancel':
                SESSIONS.pop(key, None)
                ai_forget(uid)
                await send(uid, 'Operação cancelada. Nenhuma consulta existente foi alterada.', MENU)
                return
            if intent == 'book':
                if not active or active.get('mode') != 'book':
                    state = {'mode': 'book', 'draft': {}, 'nonce': secrets.token_hex(16), 'expires': time.monotonic() + 1200}
                    SESSIONS[key] = state
                for field in ('name', 'day', 'clock', 'duration'):
                    if field in result:
                        state['draft'][field] = result[field]
                state['expires'] = time.monotonic() + 1200
                await prompt(uid, state)
                return
            if intent in ('agenda', 'slots'):
                if not result.get('day') and intent == 'slots':
                    SESSIONS[key] = {'mode': 'slots', 'expires': time.monotonic() + 1200}
                    await send(uid, 'Para qual dia deseja consultar os horários livres?')
                    return
                day = datetime.fromisoformat(result.get('day') or datetime.now(TZ).date().isoformat()).strftime('%d/%m/%Y')
                routed = dict(update, _ai_resolved=True)
                routed['message'] = dict(message, text=('agenda ' if intent == 'agenda' else '') + day)
                if intent == 'slots':
                    SESSIONS[key] = {'mode': 'slots', 'expires': time.monotonic() + 1200}
                await handle(routed)
                return
        elif not state and not re.match(r'^(marque|marcar|agende|agendar|agenda)\b', value):
            await send(uid, 'A conversa com IA está temporariamente indisponível. Você pode continuar pelos botões da agenda.', MENU)
            return
    if value in ('agenda de hoje', '/agenda') or value.startswith('agenda '):
        day = get_day(text) or datetime.now(TZ).date()
        a = datetime.combine(day, datetime.min.time(), TZ)
        events = await run_in_threadpool(list_events, time_min=a.isoformat(), time_max=(a + timedelta(days=1)).isoformat())
        lines = [f'Agenda de {day:%d/%m/%Y}']
        for event in events[:25]:
            when = event.get('start', {}).get('dateTime')
            label = datetime.fromisoformat(when.replace('Z', '+00:00')).astimezone(TZ).strftime('%H:%M') if when else 'Dia inteiro'
            status = ((event.get('extendedProperties') or {}).get('private') or {}).get('status') or 'Agendado'
            lines.append(label + ' — ' + (event.get('summary') or 'Consulta')[:100] + ' · ' + status)
        if not events:
            lines.append('Nenhum agendamento neste dia.')
        await send(uid, '\n'.join(lines), MENU)
        return
    if value == 'horarios livres' or value.startswith('/horarios'):
        SESSIONS[key] = {'mode': 'slots', 'expires': time.monotonic() + 1200}
        await send(uid, 'Para qual dia deseja consultar os horários livres?')
        return
    if state and state.get('mode') == 'slots':
        day = get_day(text)
        if not day:
            await send(uid, 'Informe o dia: hoje, amanhã ou 23/10/2026.')
            return
        result = await run_in_threadpool(slots, day=day.isoformat())
        labels = ', '.join(s['label'] for s in result['slots'])
        await send(uid, f'Horários livres em {day:%d/%m/%Y}:\n' + (labels or 'Nenhum disponível.') + '\nDisponibilidade em intervalos de 15 minutos; a duração completa será conferida ao salvar.', MENU)
        SESSIONS.pop(key, None)
        return
    if value == 'novo agendamento' or re.match(r'^(marque|marcar|agende|agendar)\b', value):
        ai_forget(uid)
        state = {'mode': 'book', 'draft': {}, 'nonce': secrets.token_hex(16), 'expires': time.monotonic() + 1200}
        SESSIONS[key] = state
        if value != 'novo agendamento':
            extract(text, state['draft'])
        await prompt(uid, state)
        return
    if state and state.get('mode') == 'book' and state.get('waiting') != 'done':
        waiting = state.get('waiting')
        if waiting == 'name':
            state['draft']['name'] = text[:150]
        else:
            extract(text, state['draft'])
            if waiting == 'duration' and value.isdigit() and int(value) in (15, 30, 45, 60, 90, 120):
                state['draft']['duration'] = int(value)
        await prompt(uid, state)
        return
    await send(uid, 'Posso consultar a agenda e agendar. Use os botões abaixo ou escreva: Marque Ana amanhã às 17h por 30 minutos.', MENU)


@router.post('/webhook')
async def webhook(request: Request, x_telegram_bot_api_secret_token: str = Header(default='')):
    if os.getenv('TELEGRAM_INTERACTION_ENABLED') != '1':
        raise HTTPException(503, 'Interação ainda não habilitada')
    if not hmac.compare_digest(x_telegram_bot_api_secret_token, webhook_secret()):
        raise HTTPException(403, 'Origem não autorizada')
    raw = await request.body()
    if len(raw) > 65536:
        raise HTTPException(413, 'Mensagem muito grande')
    try:
        update = await request.json()
    except ValueError:
        raise HTTPException(400, 'Mensagem inválida') from None
    if not isinstance(update, dict) or not isinstance(update.get('update_id'), int):
        raise HTTPException(400, 'Mensagem inválida')
    async with LOCK:
        now = time.monotonic()
        for key in list(SEEN):
            if SEEN[key] < now:
                del SEEN[key]
        for key in list(SESSIONS):
            if SESSIONS[key]['expires'] < now:
                del SESSIONS[key]
        update_id = update['update_id']
        if update_id in SEEN:
            return {'ok': True}
        try:
            await handle(update)
        except (CalendarError, ValueError) as error:
            message = (update.get('callback_query') or {}).get('message') or update.get('message') or {}
            uid = (message.get('chat') or {}).get('id')
            if allowed(uid):
                await send(uid, error.message if isinstance(error, CalendarError) else 'Confira a data e o horário informados.')
        SEEN[update_id] = now + 86400
    return {'ok': True}


class OperatorReply(BaseModel):
    chat_id: str = Field(pattern=r'^[1-9][0-9]{0,19}$')
    text: str = Field(min_length=1, max_length=3500)


@router.post('/operator-reply')
async def operator_reply(body: OperatorReply, authorization: str = Header(default='')):
    token = os.getenv('INTERNAL_API_TOKEN', '')
    if not token or not hmac.compare_digest(authorization, 'Bearer ' + token):
        raise HTTPException(403, 'Acesso não autorizado')
    if not allowed(body.chat_id):
        raise HTTPException(403, 'Conta Telegram não autorizada')
    await send(body.chat_id, 'Dr. Márcio: ' + body.text.strip(), actor='operator')
    return {'sent': True}


async def configure_webhook():
    """Register only when explicitly enabled; existing notifications keep working."""
    if os.getenv('TELEGRAM_INTERACTION_ENABLED') != '1':
        return
    base = os.getenv('RENDER_EXTERNAL_URL', '').rstrip('/')
    if not base.startswith('https://') or not owner():
        logging.getLogger(__name__).warning('Telegram interaction missing URL or owner')
        return
    try:
        await api('setWebhook', {'url': base + '/telegram/webhook', 'secret_token': webhook_secret(),
                                 'allowed_updates': ['message', 'callback_query'], 'max_connections': 1})
        logging.getLogger(__name__).info('Telegram interaction webhook configured')
    except HTTPException:
        logging.getLogger(__name__).warning('Telegram webhook configuration failed')
