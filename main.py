import os
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel
import httpx

app = FastAPI()

# Lê a chave do Render (chaves novas do AI Studio começam com AQ.)
GEMINI_API_KEY = os.getenv("GEMINI_API_KEY")

class PromptRequest(BaseModel):
    prompt: str

@app.post("/gerar")
async def gerar_texto(request: PromptRequest):
    if not GEMINI_API_KEY:
        raise HTTPException(status_code=500, detail="Chave API não configurada")

    # Endpoint oficial da API do Gemini
    url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent"
    
    # Header correto para chaves AQ. (Authorization Bearer não funciona com elas)
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
