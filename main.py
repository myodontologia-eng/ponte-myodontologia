import os
import httpx
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel

app = FastAPI()

# URL do Webhook do seu WordPress na Hostinger
WORDPRESS_WEBHOOK_URL = (
    "https://sistema.myodontologia.com/?wpwhpro_action=criar-prontuario&wpwhpro_api_key=fgguxdfyxvyn48tvhdgzyffy1qzzjimteb7uupxagmjcxcepemqdeox50aczp"
)


class EventoPayload(BaseModel):
  description: str
  start: str
  end: str


@app.post("/processar-agendamento")
async def processar_agendamento(payload: EventoPayload):
  prompt_texto = f"""
    Extraia do texto abaixo as seguintes informações do paciente e responda APENAS em formato JSON válido, sem blocos de código markdown ou texto adicional:

    - nome
    - data_nascimento (formato DD/MM/AAAA)
    - telefone
    - motivo
    - responsavel (nome do responsável, se for menor de idade; caso contrário, null)
    - data_agendamento (formato AAAA-MM-DD da data marcada para a consulta)
    - horario_agendamento (formato HH:MM do horário de início)
    - horario_final (formato HH:MM do horário de término)
    - Status

    Texto do evento:
    {payload.description}

    Início: {payload.start}
    Término: {payload.end}

    Se alguma informação não estiver presente, coloque null.
    """

  api_key = os.getenv("GEMINI_API_KEY")
  if not api_key:
    raise HTTPException(
        status_code=500, detail="Chave da API do Gemini não configurada."
    )

  gemini_url = f"https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={api_key}"
  gemini_body = {"contents": [{"parts": [{"text": prompt_texto}]}]}

  async with httpx.AsyncClient() as client:
    try:
      # 1. Comunica com o Gemini
      response_gemini = await client.post(
          gemini_url, json=gemini_body, timeout=30.0
      )
      response_gemini.raise_for_status()
      dados_gemini = response_gemini.json()

      texto_gerado = (
          dados_gemini.get("candidates", [{}])[0]
          .get("content", {})
          .get("parts", [{}])[0]
          .get("text", "{}")
      )

      # Limpa possíveis formatações de markdown caso o Gemini envie
      import json

      limpo = texto_gerado.replace("```json", "").replace("```", "").strip()
      dados_json = json.loads(limpo)

      # 2. Envia os dados estruturados para o Webhook do WordPress
      response_wp = await client.post(
          WORDPRESS_WEBHOOK_URL, json=dados_json, timeout=30.0
      )

    except Exception as e:
      raise HTTPException(
          status_code=500, detail=f"Erro ao processar ou enviar: {str(e)}"
      )

  return {
      "status": "sucesso",
      "wordpress_status": response_wp.status_code,
      "dados_enviados": dados_json,
  }
