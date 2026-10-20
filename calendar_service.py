import os
from google.oauth2 import service_account
from googleapiclient.discovery import build
from googleapiclient.errors import HttpError
from typing import List, Dict, Any, Optional
from datetime import datetime

SCOPES = ["https://www.googleapis.com/auth/calendar.events"]

def get_calendar_service():
    """Cria o serviço do Google Calendar usando a conta de serviço."""
    creds_path = os.getenv("GOOGLE_APPLICATION_CREDENTIALS")
    if not creds_path or not os.path.exists(creds_path):
        raise RuntimeError("GOOGLE_APPLICATION_CREDENTIALS não configurado ou arquivo não encontrado")

    credentials = service_account.Credentials.from_service_account_file(
        creds_path, scopes=SCOPES
    )
    return build("calendar", "v3", credentials=credentials, cache_discovery=False)

def list_events(max_results: int = 50) -> List[Dict[str, Any]]:
    """Lista eventos da agenda definida em GOOGLE_CALENDAR_ID."""
    calendar_id = os.getenv("GOOGLE_CALENDAR_ID")
    if not calendar_id:
        raise RuntimeError("GOOGLE_CALENDAR_ID não configurado")

    try:
        service = get_calendar_service()
        events_result = (
            service.events()
            .list(
                calendarId=calendar_id,
                maxResults=max_results,
                singleEvents=True,
                orderBy="startTime",
            )
            .execute()
        )
        events = events_result.get("items", [])

        safe_events = []
        for event in events:
            safe_events.append({
                "id": event.get("id"),
                "summary": event.get("summary"),
                "start": event.get("start"),
                "end": event.get("end"),
                "extendedProperties": event.get("extendedProperties"),
            })
        return safe_events

    except HttpError as error:
        raise RuntimeError(f"Erro na API do Google Calendar: {error.status_code}") from error
    except Exception as error:
        raise RuntimeError("Erro interno ao consultar a agenda") from error

def update_event_status(
    event_id: str,
    status: str,
    start: Optional[str] = None,
    end: Optional[str] = None,
    user: Optional[str] = None
) -> Dict[str, Any]:
    """Atualiza o status (e opcionalmente horários) de um evento existente."""
    calendar_id = os.getenv("GOOGLE_CALENDAR_ID")
    if not calendar_id:
        raise RuntimeError("GOOGLE_CALENDAR_ID não configurado")

    try:
        service = get_calendar_service()

        # Busca o evento atual
        event = service.events().get(calendarId=calendar_id, eventId=event_id).execute()

        old_status = None
        if event.get("extendedProperties") and event["extendedProperties"].get("private"):
            old_status = event["extendedProperties"]["private"].get("status")

        # Prepara extendedProperties
        private_props = event.get("extendedProperties", {}).get("private", {})
        private_props["status"] = status
        if user:
            private_props["updated_by"] = user
        private_props["updated_at"] = datetime.utcnow().isoformat() + "Z"

        event["extendedProperties"] = {"private": private_props}

        # Atualiza horários se fornecidos (ex: remarcação)
        if start:
            event["start"] = {"dateTime": start}
        if end:
            event["end"] = {"dateTime": end}

        # Atualiza o evento (preserva ID e demais dados)
        updated = service.events().update(
            calendarId=calendar_id,
            eventId=event_id,
            body=event
        ).execute()

        return {
            "id": updated.get("id"),
            "summary": updated.get("summary"),
            "start": updated.get("start"),
            "end": updated.get("end"),
            "extendedProperties": updated.get("extendedProperties"),
            "previous_status": old_status,
            "new_status": status,
            "updated_at": private_props["updated_at"],
            "updated_by": user,
        }

    except HttpError as error:
        if error.status_code == 404:
            raise RuntimeError("Evento não encontrado") from error
        raise RuntimeError(f"Erro na API do Google Calendar: {error.status_code}") from error
    except Exception as error:
        raise RuntimeError("Erro interno ao atualizar o evento") from error
