import os
import hashlib
import re
from datetime import datetime, timedelta, timezone
from google.oauth2 import service_account
from googleapiclient.discovery import build
from googleapiclient.errors import HttpError

SCOPES = ['https://www.googleapis.com/auth/calendar.events']
ALLOWED_STATUSES = ['Agendado', 'Atendido', 'Faltou', 'Remarcado', 'Atendimento atrasado', 'Desmarcou']

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
    return {key: event.get(key) for key in ('id', 'summary', 'description', 'start', 'end', 'extendedProperties', 'updated', 'etag', 'status')} | {'calendar_id': calendar_id()}

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
            result = service.events().list(calendarId=calendar_id(), maxResults=min(max_results, 2500), timeMin=time_min, timeMax=time_max, singleEvents=True, orderBy='startTime', showDeleted=False, pageToken=page).execute()
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
        try:
            result = service.events().insert(calendarId=cid, body=body, sendUpdates='none').execute()
        except HttpError as error:
            if int(error.resp.status) != 409:
                raise
            result = service.events().get(calendarId=cid, eventId=event_id).execute()
            if result.get('extendedProperties', {}).get('private', {}).get('request_hash') != fingerprint:
                raise CalendarError(409, 'Identificador já utilizado para outro agendamento')
        return safe_event(result)
    except HttpError as error:
        translate_error(error)

def update_event_status(event_id, status, start=None, end=None, user=None, etag=None):
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
        if start:
            body.update(start={'dateTime': start}, end={'dateTime': end})
        request = service.events().patch(calendarId=cid, eventId=event_id, body=body, sendUpdates='none')
        request.headers['If-Match'] = event['etag']
        updated = request.execute()
        return safe_event(event | body | updated) | {'previous_status': previous, 'new_status': status, 'updated_at': props['updated_at'], 'updated_by': user}
    except HttpError as error:
        translate_error(error)
