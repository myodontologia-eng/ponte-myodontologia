import os
import unittest
from unittest.mock import AsyncMock, MagicMock, patch
import telegram_service as telegram

class ContactTests(unittest.IsolatedAsyncioTestCase):
    def test_patient_phone_does_not_pick_guardian(self):
        self.assertEqual(telegram.appointment_phone({'description': 'Telefone do responsável: (44) 99999-1111'}), '')
        self.assertEqual(telegram.appointment_phone({'description': 'Telefone: Não informado'}), '')
        self.assertEqual(telegram.appointment_phone({'description': 'Telefone: (44) 99888-2222\nTelefone do responsável: (44) 99999-1111'}), '(44) 99888-2222')

    async def test_exact_link_uses_current_ficha_phone(self):
        response = MagicMock()
        response.json.return_value = {'linked': True, 'phone': '(44) 99888-2222'}
        client = AsyncMock()
        client.post.return_value = response
        event = {'id': 'test-event', 'description': 'Telefone: (44) 99777-3333'}
        with patch.dict(os.environ, {'INTERNAL_API_TOKEN': 'test-token'}), patch.object(telegram.httpx, 'AsyncClient') as factory, patch.object(telegram, 'calendar_id', return_value='test-calendar'):
            factory.return_value.__aenter__.return_value = client
            self.assertEqual(await telegram.contact_phone(event), '(44) 99888-2222')
        self.assertEqual(client.post.call_args.kwargs['json'], {'event_id': 'test-event', 'calendar_id': 'test-calendar'})

    async def test_linked_ficha_without_phone_does_not_use_stale_number(self):
        response = MagicMock()
        response.json.return_value = {'linked': True, 'phone': ''}
        client = AsyncMock()
        client.post.return_value = response
        with patch.dict(os.environ, {'INTERNAL_API_TOKEN': 'test-token'}), patch.object(telegram.httpx, 'AsyncClient') as factory, patch.object(telegram, 'calendar_id', return_value='test-calendar'):
            factory.return_value.__aenter__.return_value = client
            self.assertEqual(await telegram.contact_phone({'id': 'test-event', 'description': 'Telefone: (44) 99777-3333'}), '')

    async def test_click_shows_phone_or_placeholder_with_confirmed_button(self):
        for phone, expected in [('', 'ainda não informado'), ('(44) 99888-2222', '(44) 99888-2222')]:
            state = {'mode': 'status', 'nonce': 'test', 'events': [{'id': 'test-event', 'summary': 'Paciente fictício'}]}
            with patch.object(telegram, 'contact_phone', new_callable=AsyncMock, return_value=phone), patch.object(telegram, 'send', new_callable=AsyncMock) as send:
                await telegram.status_callback(123, 'choose:test:0', state)
            self.assertIn('Telefone: ' + expected, send.call_args.args[1])
            self.assertIn('Confirmado', [r[0]['text'] for r in send.call_args.args[2]['inline_keyboard']])

if __name__ == '__main__':
    unittest.main()
