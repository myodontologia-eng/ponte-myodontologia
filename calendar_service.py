import os
from google.oauth2 import service_account
from googleapiclient.discovery import build
from googleapiclient.errors import HttpError
from typing import List, Dict, Any

SCOPES = ["https://www.googleapis.com/auth/calendar.readonly"]

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
    """Lista eventos da agenda definida em GOOGLE_CALENDAR_ID (somente leitura)."""
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
