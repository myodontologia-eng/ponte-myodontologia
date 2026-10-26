import os
import secrets
from typing import Optional
import httpx
from fastapi import FastAPI, HTTPException, Depends, Header, Query
from fastapi.security import HTTPBearer, HTTPAuthorizationCredentials
from pydantic import BaseModel, Field
from starlette.concurrency import run_in_threadpool
from calendar_service import list_events, update_event_status, create_event, calendar_id, CalendarError

from myla_service import FAQRequest, BookingRequest, faq as myla_faq, slots as myla_slots, book as myla_book, create_checked

app = FastAPI()
security = HTTPBearer()
GEMINI_API_KEY = os.getenv("GEMINI_API_KEY")
INTERNAL_API_TOKEN = os.getenv("INTERNAL_API_TOKEN")

class PromptRequest(BaseModel):
    prompt: str

class UpdateStatusRequest(BaseModel):
    phone: Optional[str] = Field(default=None, max_length=40)
    status: str = Field(max_length=40)
    start: Optional[str] = None
    end: Optional[str] = None
    user: Optional[str] = Field(default=None, max_length=200)
    etag: Optional[str] = None

class CreateEventRequest(BaseModel):
    summary: str = Field(min_length=1, max_length=300)
    description: str = Field(default="", max_length=8000)
    start: str
    end: str
    user: Optional[str] = Field(default=None, max_length=200)

def verify_token(credentials: HTTPAuthorizationCredentials = Depends(security)):
    if not INTERNAL_API_TOKEN:
        raise HTTPException(status_code=503, detail="Token interno não configurado")
    if not secrets.compare_digest(credentials.credentials, INTERNAL_API_TOKEN):
        raise HTTPException(status_code=401, detail="Token inválido")

async def calendar_call(fn, **kwargs):
    try:
        return await run_in_threadpool(fn, **kwargs)
    except CalendarError as error:
        raise HTTPException(status_code=error.code, detail=error.message) from error
    except Exception as error:
        raise HTTPException(status_code=502, detail="Serviço da agenda temporariamente indisponível") from error

def verify_calendar(x_calendar_id: Optional[str] = Header(default=None)):
    if x_calendar_id and x_calendar_id != calendar_id():
        raise HTTPException(status_code=409, detail="A agenda da ponte mudou. Confira a configuração antes de gravar")

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


@app.get("/api/calendar/events", dependencies=[Depends(verify_token)])
async def get_calendar_events(time_min: Optional[str] = Query(default=None), time_max: Optional[str] = Query(default=None)):
    events = await calendar_call(list_events, time_min=time_min, time_max=time_max)
    return {"events": events, "calendar_id": calendar_id()}

@app.post("/api/calendar/events", dependencies=[Depends(verify_token), Depends(verify_calendar)])
async def post_calendar_event(body: CreateEventRequest, idempotency_key: str = Header(min_length=16, max_length=128)):
    return await calendar_call(create_checked, **body.model_dump(), idempotency_key=idempotency_key)

@app.patch("/api/calendar/events/{event_id}", dependencies=[Depends(verify_token), Depends(verify_calendar)])
async def patch_calendar_event(event_id: str, body: UpdateStatusRequest):
    if not event_id or len(event_id) > 1024:
        raise HTTPException(status_code=422, detail="Identificador inválido")
    return await calendar_call(update_event_status, event_id=event_id, **body.model_dump())


# The website sends only allowlisted generic FAQ topics to Gemini.
@app.post("/api/myla/faq", dependencies=[Depends(verify_token)])
async def post_myla_faq(body: FAQRequest):
    return await myla_faq(body.topic)

@app.post("/api/myla/test", dependencies=[Depends(verify_token)])
async def post_myla_test():
    return await myla_faq("saudacao")

@app.get("/api/myla/slots", dependencies=[Depends(verify_token), Depends(verify_calendar)])
async def get_myla_slots(day: str = Query(min_length=10, max_length=10)):
    return await calendar_call(myla_slots, day=day)

@app.post("/api/myla/book", dependencies=[Depends(verify_token), Depends(verify_calendar)])
async def post_myla_book(body: BookingRequest):
    return await calendar_call(myla_book, body=body)


@app.on_event("startup")
async def migrate_former_calendar():
    if os.getenv("MIGRATE_FROM_CALENDAR_ID"):
        import logging
        from calendar_migration import run
        try:
            await run_in_threadpool(run)
        except Exception as error:
            logging.getLogger("uvicorn.error").error("CALENDAR_MIGRATION incomplete (%s); original calendar unchanged", type(error).__name__)


from telegram_service import router as telegram_router, configure_webhook
app.include_router(telegram_router)


@app.on_event("startup")
async def start_telegram_interaction():
    await configure_webhook()


# Private clinical drafts and consented public conversation; neither route can write records.
from clinical_service import ClinicalRequest, draft_clinical
from conversation_service import ConversationRequest, converse

@app.post('/api/myla/clinical/draft', dependencies=[Depends(verify_token)])
async def post_clinical_draft(body: ClinicalRequest):
    return await draft_clinical(body)

@app.post('/api/myla/conversation', dependencies=[Depends(verify_token)])
async def post_myla_conversation(body: ConversationRequest):
    return await converse(body)
