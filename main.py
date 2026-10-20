import os
from fastapi import FastAPI, HTTPException, Depends, Header
from fastapi.security import HTTPBearer, HTTPAuthorizationCredentials
from pydantic import BaseModel
import httpx
from calendar_service import list_events

app = FastAPI()
security = HTTPBearer()

# Lê a chave do Render (chaves novas do AI Studio começam com AQ.)
GEMINI_API_KEY = os.getenv("GEMINI_API_KEY")
INTERNAL_API_TOKEN = os.getenv("INTERNAL_API_TOKEN")

class PromptRequest(BaseModel):
    prompt: str

def verify_token(credentials: HTTPAuthorizationCredentials = Depends(security)):
    """Protege a rota com Bearer Token."""
    if not INTERNAL_API_TOKEN:
        raise HTTPException(status_code=500, detail="Token interno não configurado")
    if credentials.credentials != INTERNAL_API_TOKEN:
        raise HTTPException(status_code=401, detail="Token inválido")
    return credentials.credentials

@app.post("/gerar")
async def gerar_texto(request: PromptRequest):
    if not GEMINI_API_KEY:
        raise HTTPException(status_code=500, detail="Chave API não configurada")

    # Modelo recomendado pelo Google
    url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent"
    
    # Header correto para chaves AQ.
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
            # Extrai o texto da resposta do Gemini
            texto_gerado = data["candidates"][0]["content"]["parts"][0]["text"]
            return {"resultado": texto_gerado}
            
        except Exception as e:
            raise HTTPException(status_code=500, detail=str(e))

@app.get("/api/calendar/events")
async def get_calendar_events(token: str = Depends(verify_token)):
    """Consulta eventos da agenda de testes (somente leitura)."""
    try:
        events = list_events()
        return {"events": events}
    except RuntimeError as e:
        raise HTTPException(status_code=500, detail=str(e))
    except Exception:
        raise HTTPException(status_code=500, detail="Erro ao consultar eventos")
