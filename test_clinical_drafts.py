import asyncio
import json
import os
import sys
import types
import unittest
from unittest.mock import patch
from fastapi import HTTPException
from pydantic import ValidationError

provider = types.ModuleType('myla_service')
provider.GEMINI_MODEL = 'gemini-test'
provider.TOPICS = {'endereco': 'Endereço aprovado'}
def provider_error(code):
    raise HTTPException(502, 'Provider unavailable')
provider.gemini_error = provider_error
original_provider = sys.modules.get('myla_service')
sys.modules['myla_service'] = provider
from clinical_service import ClinicalRequest, ClinicalDraft, draft_clinical
from conversation_service import ConversationRequest, converse
if original_provider is None:
    sys.modules.pop('myla_service', None)
else:
    sys.modules['myla_service'] = original_provider

class Reply:
    status_code = 200
    finish = 'STOP'
    value = {}
    def json(self):
        return {'candidates': [{'finishReason': self.finish, 'content': {'parts': [{'text': json.dumps(self.value)}]}}]}

class Client:
    sent = []
    def __init__(self, **kwargs): pass
    async def __aenter__(self): return self
    async def __aexit__(self, *args): pass
    async def post(self, url, **kwargs):
        self.sent.append(kwargs['json'])
        return Reply()

class DraftTests(unittest.TestCase):
    def setUp(self):
        Client.sent = []
        Reply.finish = 'STOP'
        Reply.value = {'soap': {'subjetivo': 'Sem dor'}, 'evolucao': {'dente': '36'},
                       'pagamentos': [{'descricao': 'Avaliação', 'valor': '150.00', 'situacao': 'A confirmar'}]}
    def request(self, consent=True):
        return ClinicalRequest(note='Retorno do implante 36, sem dor.', reference_date='2026-10-23', consent=consent)
    def test_consent_blocks_provider_call(self):
        with self.assertRaises(HTTPException) as error:
            asyncio.run(draft_clinical(self.request(False)))
        self.assertEqual(error.exception.status_code, 422)
        self.assertEqual(Client.sent, [])
    @patch.dict(os.environ, {'GEMINI_API_KEY': 'test-only'})
    @patch('httpx.AsyncClient', Client)
    def test_draft_does_not_save_and_sends_only_note_and_date(self):
        result = asyncio.run(draft_clinical(self.request()))
        self.assertFalse(result['saved'])
        payload = json.loads(Client.sent[0]['contents'][0]['parts'][0]['text'])
        self.assertEqual(set(payload), {'data_referencia', 'anotacao'})
        self.assertNotIn('tools', Client.sent[0])
    @patch.dict(os.environ, {'GEMINI_API_KEY': 'test-only'})
    @patch('httpx.AsyncClient', Client)
    def test_incomplete_and_unexpected_output_rejected(self):
        for invalid in ['incomplete', 'unknown_key', 'bad_amount']:
            self.setUp()
            if invalid == 'incomplete': Reply.finish = 'MAX_TOKENS'
            elif invalid == 'unknown_key': Reply.value['execute'] = 'book'
            else: Reply.value['pagamentos'][0]['valor'] = '-150.00'
            with self.assertRaises(HTTPException) as error:
                asyncio.run(draft_clinical(self.request()))
            self.assertEqual(error.exception.status_code, 502)
    def test_identity_and_oversized_note_not_part_of_request_contract(self):
        for extra in [{'patient_name': 'EXEMPLO'}, {'note': 'x'*8001}]:
            with self.assertRaises(ValidationError):
                ClinicalRequest.model_validate({'note': 'Exemplo fictício', 'reference_date': '2026-10-23', **extra})
    @patch.dict(os.environ, {'GEMINI_API_KEY': 'test-only'})
    @patch('httpx.AsyncClient', Client)
    def test_public_chat_is_read_only_and_history_bounded(self):
        Reply.value = {'intent': 'chat', 'answer': 'Boa tarde! Como você está?', 'normalized': ''}
        reply = asyncio.run(converse(ConversationRequest(message='Boa tarde', consent=True)))
        self.assertEqual(reply['intent'], 'chat')
        self.assertNotIn('tools', Client.sent[0])
        with self.assertRaises(ValidationError):
            ConversationRequest(message='Oi', history=[{'role':'user','content':'Oi'}]*9)

if __name__ == '__main__': unittest.main()
