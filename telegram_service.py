"""Private Telegram scheduling. No patient text is sent to an AI provider."""
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
from starlette.concurrency import run_in_threadpool
from calendar_service import CalendarError, list_events
from myla_service import create_checked, slots

router = APIRouter(prefix='/telegram', tags=['Telegram'])
TZ = ZoneInfo('America/Sao_Paulo')
LOCK = asyncio.Lock()
SESSIONS = {}
SEEN = {}
MENU = {'keyboard': [[{'text': 'Agenda de hoje'}, {'text': 'Horários livres'}],
                     [{'text': 'Novo agendamento'}, {'text': 'Cancelar operação'}]],
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


async def send(chat, text, markup=None):
    body = {'chat_id': chat, 'text': text[:4000], 'protect_content': True}
    if markup:
        body['reply_markup'] = markup
    return await api('sendMessage', body)


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
    d['start'], d['end'] = start.isoformat(), end.isoformat()
    await send(chat, f"Confirma o agendamento?\nPaciente: {d['name']}\nDia: {start:%d/%m/%Y}\nHorário: {start:%H:%M}–{end:%H:%M}\nDuração: {d['duration']} minutos",
               {'inline_keyboard': [[{'text': 'Confirmar agendamento', 'callback_data': 'confirm:' + state['nonce']},
                                     {'text': 'Cancelar', 'callback_data': 'cancel:' + state['nonce']}]]})


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
    if callback:
        await api('answerCallbackQuery', {'callback_query_id': callback['id']})
    key = str(uid)
    state = SESSIONS.get(key)
    if state and state['expires'] < time.monotonic():
        SESSIONS.pop(key, None)
        state = None
    if callback:
        action, _, nonce = callback.get('data', '').partition(':')
        if not state or nonce != state.get('nonce'):
            await send(uid, 'Esta confirmação expirou. Comece um novo agendamento.', MENU)
            return
        if action == 'cancel':
            SESSIONS.pop(key, None)
            await send(uid, 'Operação cancelada. Nenhuma consulta foi criada.', MENU)
        elif action == 'confirm' and state.get('waiting') == 'confirm':
            d = state['draft']
            await run_in_threadpool(create_checked, summary=d['name'], description='Agendado pela equipe via Telegram.',
                                            start=d['start'], end=d['end'], idempotency_key='telegram-' + nonce, user='telegram:' + key)
            state['waiting'] = 'done'
            await send(uid, 'Agendamento salvo na agenda central.', MENU)
        return
    text = message.get('text', '').strip()[:1000]
    value = normalized(text)
    if value in ('/start', '/menu', 'menu', '/cancelar', 'cancelar', 'cancelar operacao'):
        SESSIONS.pop(key, None)
        await send(uid, 'Sou a Myla. Consulte a agenda ou faça um agendamento pelos botões. Também pode escrever: Marque Ana amanhã às 17h por 30 minutos.', MENU)
        return
    if message.get('photo') or message.get('voice') or message.get('document'):
        await send(uid, 'Nesta primeira etapa, envie os dados por texto. A leitura de imagens e áudios ainda não está habilitada.')
        return
    if value in ('agenda de hoje', '/agenda') or value.startswith('agenda '):
        day = get_day(text) or datetime.now(TZ).date()
        a = datetime.combine(day, datetime.min.time(), TZ)
        events = await run_in_threadpool(list_events, time_min=a.isoformat(), time_max=(a + timedelta(days=1)).isoformat())
        lines = [f'Agenda de {day:%d/%m/%Y}']
        for event in events[:25]:
            when = event.get('start', {}).get('dateTime')
            label = datetime.fromisoformat(when.replace('Z', '+00:00')).astimezone(TZ).strftime('%H:%M') if when else 'Dia inteiro'
            lines.append(label + ' — ' + (event.get('summary') or 'Consulta')[:100])
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
