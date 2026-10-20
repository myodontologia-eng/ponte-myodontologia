import os
from fastapi import FastAPI, HTTPException, Depends
from fastapi.security import HTTPBearer, HTTPAuthorizationCredentials
from pydantic import BaseModel
from typing import Optional
import httpx
from calendar_service import list_events, update_event_status

app = FastAPI()
security = HTTPBearer()

GEMINI_API_KEY = os.getenv("GEMINI_API_KEY")
INTERNAL_API_TOKEN = os.getenv("INTERNAL_API_TOKEN")

ALLOWED_STATUSES = [
    "Agendado",
    "Atendido",
    "Faltou",
    "Remarcado",
    "Atendimento atrasado",
    "Desmarcou",
]

class PromptRequest(BaseModel):
    prompt: str

class UpdateStatusRequest(BaseModel):
    status: str
    start: Optional[str] = None
    end: Optional[str] = None
    user: Optional[str] = None

def verify_token(credentials: HTTPAuthorizationCredentials = Depends(security)):
    if not INTERNAL_API_TOKEN:
        raise HTTPException(status_code=500, detail="Token interno não configurado")
    if credentials.credentials != INTERNAL_API_TOKEN:
        raise HTTPException(status_code=401, detail="Token inválido")
    return credentials.credentials

@app.post("/gerar")
async def gerar_texto(request: PromptRequest):
    if not GEMINI_API_KEY:
        raise HTTPException(status_code=500, detail="Chave API não configurada")

    url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent"
    headers = {
        "x-goog-api-key": GEMINI_API_KEY,
        "Content-Type": "application/json"
    }
    payload = {
        "contents": [{
            "parts": [{"text": request.prompt}]
        }]
    }

    async with httpx.AsyncClient() as client:
        try:
            response = await client.post(url, headers=headers, json=payload, timeout=30.0)
            if response.status_code != 200:
                raise HTTPException(status_code=response.status_code, detail=response.text)
            data = response.json()
            texto_gerado = data["candidates"][0]["content"]["parts"][0]["text"]
            return {"resultado": texto_gerado}
        except Exception as e:
            raise HTTPException(status_code=500, detail=str(e))

@app.get("/api/calendar/events")
async def get_calendar_events(token: str = Depends(verify_token)):
    try:
        events = list_events()
        return {"events": events}
    except RuntimeError as e:
        raise HTTPException(status_code=500, detail=str(e))
    except Exception:
        raise HTTPException(status_code=500, detail="Erro ao consultar eventos")

@app.patch("/api/calendar/events/{event_id}")
async def patch_calendar_event(
    event_id: str,
    body: UpdateStatusRequest,
    token: str = Depends(verify_token)
):
    if body.status not in ALLOWED_STATUSES:
        raise HTTPException(status_code=400, detail="Status inválido")

    try:
        result = update_event_status(
            event_id=event_id,
            status=body.status,
            start=body.start,
            end=body.end,
            user=body.user
        )
        return result
    except RuntimeError as e:
        msg = str(e)
        if "não encontrado" in msg.lower():
            raise HTTPException(status_code=404, detail="Evento não encontrado")
        raise HTTPException(status_code=500, detail="Erro ao atualizar evento")
    except Exception:
        raise HTTPException(status_code=500, detail="Erro ao atualizar evento")
