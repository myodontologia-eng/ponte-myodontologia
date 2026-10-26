import os
from appointment_identity import appointment_identity
import hashlib
import re
import logging
import httpx
from zoneinfo import ZoneInfo
from datetime import datetime, timedelta, timezone
from google.oauth2 import service_account
from googleapiclient.discovery import build
from googleapiclient.errors import HttpError

SCOPES = ['https://www.googleapis.com/auth/calendar.events']
ALLOWED_STATUSES = ['Agendado', 'Confirmado', 'Aguardando', 'Atendido', 'Faltou', 'Remarcado', 'Atendimento atrasado', 'Desmarcou']

class CalendarError(Exception):
    def __init__(self, code, message):
        self.code, self.message = code, message
        super().__init__(message)

def get_calendar_service():
    path = os.getenv('GOOGLE_APPLICATION_CREDENTIALS')
    if not path or not os.path.isfile(path):
        raise CalendarError(503, 'Credencial da agenda indisponível')
    credentials = service_account.Credentials.from_service_account_file(path, scopes=SCOPES)
    return build('calendar', 'v3', credentials=credentials, cache_discovery=False)

def calendar_id():
    value = os.getenv('GOOGLE_CALENDAR_ID')
    if not value:
        raise CalendarError(503, 'Agenda não configurada')
    return value

def parse_time(value):
    try:
        result = datetime.fromisoformat(value.replace('Z', '+00:00'))
        if result.tzinfo is None or result.utcoffset() is None:
            raise ValueError()
        return result
    except (ValueError, TypeError, AttributeError):
        raise CalendarError(422, 'Informe data e horário com fuso horário')

def validate_period(start, end):
    if parse_time(end) <= parse_time(start):
        raise CalendarError(422, 'O término deve ser posterior ao início')

def safe_event(event):
    if event.get('status') == 'cancelled':
        return {key: event[key] for key in ('id', 'summary', 'description', 'start', 'end', 'etag', 'status', 'updated', 'extendedProperties') if event.get(key) is not None} | {'calendar_id': calendar_id()}
    identity = appointment_identity(event)
    return {key: event.get(key) for key in ('id', 'summary', 'description', 'start', 'end', 'extendedProperties', 'updated', 'etag', 'status')} | {
        'calendar_id': calendar_id(), 'summary': identity['name'],
        'patient_name': identity['name'], 'patient_reason': identity['reason']}

def translate_error(error):
    code = int(error.resp.status)
    messages = {404: 'Evento ou agenda não encontrado', 403: 'A conta de serviço não tem permissão nesta agenda', 412: 'O evento mudou. Atualize a agenda antes de salvar', 429: 'Limite temporário do Google Calendar'}
    raise CalendarError(code if code in messages else 502, messages.get(code, 'Falha no Google Calendar')) from error

def list_events(max_results=250, time_min=None, time_max=None):
    now = datetime.now(timezone.utc)
    time_min = time_min or (now - timedelta(days=30)).isoformat()
    time_max = time_max or (now + timedelta(days=365)).isoformat()
    validate_period(time_min, time_max)
    if parse_time(time_max) - parse_time(time_min) > timedelta(days=366):
        raise CalendarError(422, 'Consulte até 366 dias por vez')
    events, page = [], None
    try:
        service = get_calendar_service()
        while True:
            result = service.events().list(calendarId=calendar_id(), maxResults=min(max_results, 2500), timeMin=time_min, timeMax=time_max, singleEvents=True, orderBy='startTime', showDeleted=True, pageToken=page).execute()
            events.extend(safe_event(event) for event in result.get('items', []))
            page = result.get('nextPageToken')
            if not page:
                return events
            if len(events) > 10000:
                raise CalendarError(422, 'Período muito amplo. Selecione menos dias')
    except HttpError as error:
        translate_error(error)

def status_description(description, status):
    line = 'Status: ' + status
    pattern = r'(?im)^\s*Status:[^\r\n]*'
    return re.sub(pattern, lambda _: line, description) if re.search(pattern, description) else (description.rstrip() + '\n' + line).lstrip()


def notify_calendar_change(action, event, previous=None, user=None):
    """Send only operational metadata; never patient names or clinical details."""
    token = os.getenv('TELEGRAM_BOT_TOKEN', '').strip()
    owner_id = os.getenv('DR_MARCIO_CHAT_ID', '').strip()
    recipients = list(dict.fromkeys([value.strip() for value in
        [owner_id, *os.getenv('TELEGRAM_SECRETARIA_IDS', '').split(',')] if value.strip()]))
    if not token or not recipients:
        return {'sent': False, 'reason': 'not_configured'}
    def display_time(item):
        value = (item or {}).get('dateTime')
        if value:
            return parse_time(value).astimezone(ZoneInfo('America/Sao_Paulo')).strftime('%d/%m/%Y às %H:%M')
        return (item or {}).get('date', 'Não informado')
    try:
        status = event.get('extendedProperties', {}).get('private', {}).get('status', 'Agendado')
        lines = ['MY Odontologia — ' + action, 'Horário: ' + display_time(event.get('start')), 'Status: ' + status]
        if previous:
            old_status = previous.get('extendedProperties', {}).get('private', {}).get('status')
            if old_status and old_status != status:
                lines.append('Status anterior: ' + old_status)
            if previous.get('start') != event.get('start'):
                lines.append('Horário anterior: ' + display_time(previous.get('start')))
        lines.append('Confira os detalhes no módulo Secretaria: https://sistema.myodontologia.com/?myod_panel=secretaria')
        delivered = []
        with httpx.Client(timeout=httpx.Timeout(8, connect=3)) as client:
            for recipient in recipients:
                try:
                    response = client.post('https://api.telegram.org/bot' + token + '/sendMessage', json={
                        'chat_id': recipient, 'text': '\n'.join(lines), 'protect_content': True,
                        'link_preview_options': {'is_disabled': True},
                        'reply_markup': {'inline_keyboard': [[{
                            'text': 'Abrir módulo Secretaria',
                            'url': 'https://sistema.myodontologia.com/?myod_panel=secretaria'}]]}})
                    if response.status_code == 200 and response.json().get('ok') is True:
                        delivered.append(recipient)
                    else:
                        logging.getLogger(__name__).warning('Calendar Telegram notification rejected (HTTP %s)', response.status_code)
                except Exception:
                    logging.getLogger(__name__).warning('Calendar Telegram recipient unavailable')
        return {'sent': owner_id in delivered if owner_id else bool(delivered),
                'all_sent': len(delivered) == len(recipients),
                'delivered_count': len(delivered), 'recipient_count': len(recipients)}

    except Exception:
        # Do not log exception text: HTTP exceptions may contain the bot token.
        logging.getLogger(__name__).warning('Calendar Telegram notification unavailable')
        return {'sent': False, 'reason': 'unavailable'}

def create_event(summary, description, start, end, idempotency_key, user=None):
    validate_period(start, end)
    cid = calendar_id()
    event_id = hashlib.sha256((cid + ':' + idempotency_key).encode()).hexdigest()
    fingerprint = hashlib.sha256((summary + '\0' + description + '\0' + start + '\0' + end).encode()).hexdigest()
    private = {'status': 'Agendado', 'request_hash': fingerprint, 'source': 'myodontologia'}
    if user:
        private['updated_by'] = user[:200]
    body = {'id': event_id, 'summary': summary, 'description': status_description(description, 'Agendado'), 'start': {'dateTime': start}, 'end': {'dateTime': end}, 'extendedProperties': {'private': private}}
    try:
        service = get_calendar_service()
        created = False
        try:
            result = service.events().insert(calendarId=cid, body=body, sendUpdates='none').execute()
            created = True
        except HttpError as error:
            if int(error.resp.status) != 409:
                raise
            result = service.events().get(calendarId=cid, eventId=event_id).execute()
            if result.get('extendedProperties', {}).get('private', {}).get('request_hash') != fingerprint:
                raise CalendarError(409, 'Identificador já utilizado para outro agendamento')
        notification = notify_calendar_change('Novo agendamento', result, user=user) if created else {'sent': False, 'reason': 'duplicate'}
        return safe_event(result) | {'telegram_notification': notification}
    except HttpError as error:
        translate_error(error)

def update_event_status(event_id, status, start=None, end=None, user=None, etag=None, phone=None):
    if status not in ALLOWED_STATUSES:
        raise CalendarError(422, 'Status inválido')
    if bool(start) != bool(end):
        raise CalendarError(422, 'Informe início e término juntos')
    if start:
        validate_period(start, end)
    try:
        service = get_calendar_service()
        cid = calendar_id()
        event = service.events().get(calendarId=cid, eventId=event_id).execute()
        if etag and etag != event.get('etag'):
            raise CalendarError(412, 'O evento mudou. Atualize a agenda antes de salvar')
        props = dict(event.get('extendedProperties', {}).get('private', {}))
        previous = props.get('status')
        props['status'] = status
        props['updated_at'] = datetime.now(timezone.utc).isoformat()
        if user:
            props['updated_by'] = user[:200]
        body = {'status': 'cancelled' if status == 'Desmarcou' else 'confirmed', 'extendedProperties': {'private': props}, 'description': status_description(event.get('description', ''), status)}
        if phone is not None:
            phone = re.sub(r'\s+', ' ', phone).strip()
            if not 10 <= len(re.sub(r'\D', '', phone)) <= 13:
                raise CalendarError(422, 'Confira o telefone com DDD')
            description = body['description']
            line = 'Telefone: ' + phone
            body['description'] = re.sub(r'(?im)^Telefone:[^\r\n]*', lambda _: line, description) if re.search(r'(?im)^Telefone:', description) else description.rstrip() + '\n' + line
        if start:
            body.update(start={'dateTime': start}, end={'dateTime': end})
        request = service.events().patch(calendarId=cid, eventId=event_id, body=body, sendUpdates='none')
        request.headers['If-Match'] = event['etag']
        updated = request.execute()
        final_event = event | body | updated
        changed = any(event.get(key) != final_event.get(key) for key in ('start', 'end', 'description', 'status')) or previous != status
        notification = notify_calendar_change('Paciente chegou — aguardando atendimento' if status == 'Aguardando' and previous != status else 'Agenda alterada', final_event, previous=event, user=user) if changed else {'sent': False, 'reason': 'unchanged'}
        return safe_event(final_event) | {'telegram_notification': notification} | {'previous_status': previous, 'new_status': status, 'updated_at': props['updated_at'], 'updated_by': user}
    except HttpError as error:
        translate_error(error)

