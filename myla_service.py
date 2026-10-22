"""Website Myla: only fixed FAQ topics are sent to Gemini; bookings stay outside AI."""
import os
import re
import hashlib
import threading
from datetime import datetime, timedelta, time, date
from zoneinfo import ZoneInfo
from typing import Literal
import httpx
from fastapi import HTTPException
from pydantic import BaseModel, Field
from googleapiclient.errors import HttpError
from calendar_service import list_events, create_event, calendar_id, parse_time, CalendarError, get_calendar_service, safe_event

TZ = ZoneInfo("America/Sao_Paulo")
WRITE_LOCK = threading.RLock()
GEMINI_MODEL = "gemini-3.8-flash"
TOPICS = {
    "horarios": "Horários de TESTE da clínica: segunda a sexta, 16h às 20h; sábado, 7h às 13h; domingo fechado. Consulta padrão de 15 minutos e antecedência mínima de 1 hora.",
    "endereco": "A MY Odontologia fica na Rua Cristóvão Colombo, 1451, Alto Paraná, Paraná.",
    "servicos": "O único profissional é Dr. Márcio Yamamoto. Serviços: implantes dentários, restaurações, cirurgia de terceiros molares, reabilitação bucal, avaliações e urgências odontológicas.",
    "valores": "Não informe preços nem estimativas. O valor depende da avaliação e do planejamento individualizado. Ofereça a opção Agendar avaliação.",
    "implantes": "Para avaliação de implantes, pergunte se já possui radiografia panorâmica. Se possuir, oriente levar. Se não, oriente falar com a clínica sobre a guia; o dentista decide quais exames são necessários. Não prometa tratamento ou resultados.",
    "siso": "Para avaliação de cirurgia do siso, pergunte se já possui radiografia panorâmica. Se possuir, oriente levar. Se não, oriente falar com a clínica sobre a guia; o dentista decide os exames necessários. Não prometa resultados.",
    "agendar": "O visitante pode clicar em Agendar consulta, informar os dados básicos, escolher um horário livre e confirmar. Só o servidor cria o evento depois da confirmação. Não afirme que já reservou.",
    "saudacao": "Apresente-se uma única vez: sou Myla, assistente virtual da MY Odontologia. Ajudo com informações da clínica e novos agendamentos. Pergunte como pode ajudar.",
}
class FAQRequest(BaseModel):
    topic: Literal["horarios", "endereco", "servicos", "valores", "implantes", "siso", "agendar", "saudacao"]

class Patient(BaseModel):
    name: str = Field(default="", max_length=150)
    birth: str = Field(default="", max_length=10)
    phone: str = Field(default="", max_length=40)
    reason: str = Field(default="", max_length=140)
    responsible: str = Field(default="", max_length=150)
    responsible_phone: str = Field(default="", max_length=40)

class BookingRequest(BaseModel):
    patient: Patient
    start: str = Field(max_length=40)
    request_id: str = Field(min_length=16, max_length=90)
    confirmed: bool = False
    allow_incomplete: bool = False

def slot_time(value, now=None):
    now = now or datetime.now(TZ)
    start = parse_time(value).astimezone(TZ)
    end = start + timedelta(minutes=15)
    opens, closes = (7, 13) if start.weekday() == 5 else (16, 20)
    if start.weekday() == 6 or start.minute % 15 or start.second or start.microsecond:
        raise CalendarError(422, "Escolha um dos horários disponíveis")
    if start < datetime.combine(start.date(), time(opens), TZ) or end > datetime.combine(start.date(), time(closes), TZ):
        raise CalendarError(422, "Horário fora do atendimento")
    if start < now + timedelta(hours=1) or start.date() > now.date() + timedelta(days=90):
        raise CalendarError(422, "Agende com ao menos 1 hora de antecedência, nos próximos 90 dias")
    return start

def bounds(event):
    a, b = event.get("start") or {}, event.get("end") or {}
    if a.get("dateTime") and b.get("dateTime"):
        return parse_time(a["dateTime"]), parse_time(b["dateTime"])
    if a.get("date") and b.get("date"):
        return datetime.combine(date.fromisoformat(a["date"]), time(), TZ), datetime.combine(date.fromisoformat(b["date"]), time(), TZ)
    raise CalendarError(502, "Não foi possível verificar todos os bloqueios da agenda")

def free(start, end, events):
    for event in events:
        if event.get("status") == "cancelled" or event.get("transparency") == "transparent":
            continue
        a, b = bounds(event)
        if start < b and end > a:
            return False
    return True

def slots(day):
    try:
        d = date.fromisoformat(day)
    except ValueError:
        raise CalendarError(422, "Data inválida")
    now = datetime.now(TZ)
    if d < now.date() or d > now.date() + timedelta(days=90):
        raise CalendarError(422, "Escolha uma data nos próximos 90 dias")
    if d.weekday() == 6:
        return {"slots": [], "calendar_id": calendar_id()}
    opens, closes = (7, 13) if d.weekday() == 5 else (16, 20)
    start, end = datetime.combine(d, time(opens), TZ), datetime.combine(d, time(closes), TZ)
    events = list_events(time_min=start.isoformat(), time_max=end.isoformat())
    result = []
    while start + timedelta(minutes=15) <= end:
        if start >= now + timedelta(hours=1) and free(start, start + timedelta(minutes=15), events):
            result.append({"start": start.isoformat(), "label": start.strftime("%H:%M")})
        start += timedelta(minutes=15)
    return {"slots": result, "calendar_id": calendar_id()}

def create_checked(summary, description, start, end, idempotency_key, user=None):
    """Serialize website/secretary creates; external Calendar clients may still race."""
    with WRITE_LOCK:
        cid = calendar_id()
        eid = hashlib.sha256((cid + ":" + idempotency_key).encode()).hexdigest()
        fingerprint = hashlib.sha256((summary + "\0" + description + "\0" + start + "\0" + end).encode()).hexdigest()
        try:
            existing = get_calendar_service().events().get(calendarId=cid, eventId=eid).execute()
            if existing.get("extendedProperties", {}).get("private", {}).get("request_hash") != fingerprint:
                raise CalendarError(409, "Identificador já utilizado")
            return safe_event(existing)
        except HttpError as error:
            if int(error.resp.status) != 404:
                raise CalendarError(502, "Não foi possível verificar o agendamento anterior")
        a, b = parse_time(start), parse_time(end)
        if b <= a:
            raise CalendarError(422, "Término deve ser posterior ao início")
        events = list_events(time_min=start, time_max=end)
        if not free(a, b, events):
            raise CalendarError(409, "O horário acabou de ser ocupado. Escolha outro.")
        return create_event(summary, description, start, end, idempotency_key, user)

def book(body):
    if not body.confirmed or not re.fullmatch(r"[A-Za-z0-9-]{16,90}", body.request_id):
        raise CalendarError(422, "Confirme os dados antes de reservar")
    p = {k: re.sub(r"\s+", " ", v).strip() for k, v in body.patient.model_dump().items()}
    now = datetime.now(TZ)
    birth = None
    if p["birth"]:
        try:
            birth = datetime.strptime(p["birth"], "%d/%m/%Y").date()
            if birth > now.date() or birth.year < now.year - 120:
                raise ValueError()
        except ValueError:
            raise CalendarError(422, "Data de nascimento inválida")
    missing = []
    if not p["name"]:
        raise CalendarError(422, "Informe o nome do paciente")
    if not birth:
        missing.append("data de nascimento")
    if not p["phone"]:
        missing.append("telefone")
    elif not 10 <= len(re.sub(r"\D", "", p["phone"])) <= 13:
        raise CalendarError(422, "Confira o telefone com DDD")
    if not p["reason"]:
        missing.append("motivo")
    age = None if birth is None else now.year - birth.year - ((now.month, now.day) < (birth.month, birth.day))
    if age is not None and age < 18:
        if len(p["responsible"].split()) < 2:
            missing.append("nome completo do responsável")
        if not p["responsible_phone"]:
            missing.append("telefone do responsável")
        elif not 10 <= len(re.sub(r"\D", "", p["responsible_phone"])) <= 13:
            raise CalendarError(422, "Confira o telefone do responsável")
    if missing and not body.allow_incomplete:
        raise CalendarError(422, "Confira os dados: " + ", ".join(missing))
    start = slot_time(body.start)
    end = start + timedelta(minutes=15)
    lines = ["Nome: " + (p["name"] or "Não informado"), "Data de nascimento: " + (p["birth"] or "Não informado"), "Telefone: " + (p["phone"] or "Não informado"), "Motivo: " + (p["reason"] or "Não informado"), "Status: Agendado"]
    if missing:
        lines += ["Cadastro incompleto — equipe deve completar: " + ", ".join(missing)]
    if p["responsible"]:
        lines += ["Responsável: " + p["responsible"], "Telefone do responsável: " + p["responsible_phone"]]
    return create_checked(summary=("[DADOS PENDENTES] " if missing else "") + (p["reason"] or "Consulta") + " - " + (p["name"] or "Nome não informado"), description="\n".join(lines), start=start.isoformat(), end=end.isoformat(), idempotency_key="myla-" + body.request_id, user="myla-site")

def gemini_error(code):
    messages = {400: "Gemini recusou a configuração. Confira a chave e o modelo.", 401: "Chave Gemini recusada.", 403: "Chave Gemini sem permissão.", 404: "Modelo gemini-3.8-flash indisponível para esta chave (HTTP 404).", 429: "Limite ou saldo da API Gemini atingido."}
    raise HTTPException(502, messages.get(code, "Gemini temporariamente indisponível."))

async def faq(topic):
    """topic is a Literal allowlist; no free text, identity or history leaves WordPress."""
    key = os.getenv("GEMINI_API_KEY")
    if not key:
        raise HTTPException(503, "GEMINI_API_KEY não configurada no Render")
    try:
        async with httpx.AsyncClient(timeout=httpx.Timeout(60, connect=10)) as client:
            model = GEMINI_MODEL
            stage = "geração de resposta (" + model + ")"
            prompt = "Você é Myla, assistente virtual da MY Odontologia. Responda em português em até 3 frases, sem inventar fatos. Não dê diagnóstico nem prescrição. Responda apenas com estas informações aprovadas: " + TOPICS[topic]
            r = await client.post("https://generativelanguage.googleapis.com/v1beta/models/" + model + ":generateContent", headers={"x-goog-api-key": key}, json={"contents": [{"parts": [{"text": prompt}]}], "generationConfig": {"maxOutputTokens": 2048, "temperature": 0.2}})
        if r.status_code != 200:
            gemini_error(r.status_code)
        parts = r.json().get("candidates", [{}])[0].get("content", {}).get("parts", [])
        answer = "".join(p.get("text", "") for p in parts if not p.get("thought")).strip()
        if not answer:
            raise HTTPException(502, "Gemini respondeu sem texto. Confira o modelo no Render.")
        return {"answer": answer[:2000], "model": model, "personal_data_sent": False}
    except httpx.HTTPError as error:
        raise HTTPException(504, "Gemini: falha na " + stage + " — " + type(error).__name__ + ". Tente novamente.")


def reschedule_checked(event_id, start, end, etag, user=None):
    """Reschedule the same event after checking its version and occupied period."""
    from calendar_service import update_event_status
    with WRITE_LOCK:
        a, b = parse_time(start), parse_time(end)
        if b <= a:
            raise CalendarError(422, "Término deve ser posterior ao início")
        try:
            current = get_calendar_service().events().get(calendarId=calendar_id(), eventId=event_id).execute()
        except HttpError:
            raise CalendarError(502, "Não foi possível conferir o agendamento")
        if current.get("etag") != etag:
            raise CalendarError(412, "O agendamento mudou. Consulte a agenda novamente antes de remarcar")
        if current.get("status") == "cancelled":
            raise CalendarError(409, "Este agendamento foi desmarcado. Atualize a agenda")
        events = list_events(time_min=start, time_max=end)
        others = [event for event in events if event.get("id") != event_id]
        if not free(a, b, others):
            raise CalendarError(409, "O novo horário está ocupado. Escolha outro")
        return update_event_status(event_id=event_id, status="Remarcado", start=start, end=end, etag=etag, user=user)
