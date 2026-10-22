import asyncio
import os
import unittest
from datetime import datetime, timedelta
from unittest.mock import AsyncMock, MagicMock, patch

os.environ.setdefault('GOOGLE_CALENDAR_ID', 'test-calendar')
from appointment_identity import appointment_identity, appointment_label
import telegram_service as telegram
from calendar_service import safe_event, CalendarError, list_events


class IdentityTest(unittest.TestCase):
    def test_legacy_title_keeps_reason_out_of_patient_name(self):
        event = {'summary': 'Ajuste de prótese - Paciente Exemplo'}
        self.assertEqual(appointment_identity(event), {'name': 'Paciente Exemplo', 'reason': 'Ajuste de prótese'})
        self.assertEqual(appointment_label(safe_event(event)), 'Paciente Exemplo — Ajuste de prótese')

    def test_explicit_name_wins_and_hyphenated_names_are_preserved(self):
        event = {'summary': 'Título antigo', 'description': 'Nome: Ana-Maria Exemplo\nMotivo: Avaliação\nStatus: Agendado'}
        self.assertEqual(appointment_identity(event), {'name': 'Ana-Maria Exemplo', 'reason': 'Avaliação'})

    def test_missing_optional_properties(self):
        self.assertEqual(appointment_identity({'summary': 'Paciente', 'extendedProperties': None})['name'], 'Paciente')

    def test_deleted_events_are_requested_and_sparse_tombstones_remain_sparse(self):
        service = MagicMock()
        tombstone = {'id': 'cancelled-event', 'status': 'cancelled', 'etag': 'v2'}
        service.events.return_value.list.return_value.execute.return_value = {'items': [tombstone]}
        with patch('calendar_service.get_calendar_service', return_value=service):
            events = list_events(time_min='2026-10-22T00:00:00-03:00', time_max='2026-10-23T00:00:00-03:00')
        self.assertTrue(service.events.return_value.list.call_args.kwargs['showDeleted'])
        self.assertEqual(events[0]['status'], 'cancelled')
        self.assertNotIn('summary', events[0])
        self.assertNotIn('start', events[0])


class TelegramAgendaTest(unittest.IsolatedAsyncioTestCase):
    async def asyncSetUp(self):
        telegram.SESSIONS.clear()
        self.day = datetime.now(telegram.TZ).date()
        self.event = {'id': 'event-test', 'etag': 'version-1', 'summary': 'Paciente Exemplo',
                      'description': 'Nome: Paciente Exemplo\nMotivo: Avaliação',
                      'start': {'dateTime': self.day.isoformat() + 'T17:00:00-03:00'}}

    async def test_agenda_buttons_open_statuses_and_only_write_after_confirmation(self):
        with patch.object(telegram, 'list_events', return_value=[self.event]), patch.object(telegram, 'send', new_callable=AsyncMock) as send, patch.object(telegram, 'update_event_status', return_value={}) as save:
            await telegram.status_list(123, self.day, agenda=True)
            button = send.call_args.args[2]['inline_keyboard'][0][0]
            self.assertIn('Paciente Exemplo — Avaliação', button['text'])
            self.assertNotIn('Paciente', button['callback_data'])
            state = telegram.SESSIONS['123']
            await telegram.status_callback(123, button['callback_data'], state)
            statuses = send.call_args.args[2]['inline_keyboard']
            waiting = next(row[0] for row in statuses if row[0]['text'] == 'Aguardando')
            await telegram.status_callback(123, waiting['callback_data'], state)
            save.assert_not_called()
            confirmation = send.call_args.args[2]['inline_keyboard'][0][0]['callback_data']
            await telegram.status_callback(123, confirmation, state)
            save.assert_called_once_with(event_id='event-test', status='Aguardando', etag='version-1', user='telegram:123')
            self.assertNotIn('123', telegram.SESSIONS)

    async def test_old_buttons_cannot_change_a_new_selection(self):
        with patch.object(telegram, 'send', new_callable=AsyncMock), patch.object(telegram, 'update_event_status') as save:
            await telegram.status_callback(123, 'choose:expired:0', {'mode': 'status', 'nonce': 'new', 'events': [self.event]})
            save.assert_not_called()

    async def test_agenda_alias_bypasses_ai(self):
        os.environ['DR_MARCIO_CHAT_ID'] = '123'
        update = {'_ai_resolved': True, 'message': {'from': {'id': 123}, 'chat': {'id': 123, 'type': 'private'}, 'text': 'agenda hoje'}}
        with patch.object(telegram, 'status_list', new_callable=AsyncMock) as listing, patch.object(telegram, 'ai_plan', new_callable=AsyncMock) as ai:
            await telegram.handle(update)
            listing.assert_awaited_once_with(123, self.day, agenda=True)
            ai.assert_not_awaited()

    async def test_free_slot_button_prepares_booking_without_reserving(self):
        tomorrow = self.day + timedelta(days=1)
        available = {'slots': [{'start': tomorrow.isoformat() + 'T17:00:00-03:00', 'label': '17:00'}]}
        with patch.object(telegram, 'slots', return_value=available), patch.object(telegram, 'send', new_callable=AsyncMock) as send, patch.object(telegram, 'create_checked') as create:
            await telegram.free_slots_list(123, tomorrow)
            button = send.call_args.args[2]['inline_keyboard'][0][0]
            self.assertEqual(button['text'], '17:00')
            await telegram.free_slot_callback(123, button['callback_data'], telegram.SESSIONS['123'])
            state = telegram.SESSIONS['123']
            self.assertEqual(state['draft'], {'day': tomorrow.isoformat(), 'clock': '17:00'})
            self.assertEqual(state['waiting'], 'name')
            create.assert_not_called()

    async def test_expired_free_slot_button_is_rejected(self):
        with patch.object(telegram, 'send', new_callable=AsyncMock), patch.object(telegram, 'prompt', new_callable=AsyncMock) as prompt:
            await telegram.free_slot_callback(123, 'slot:old:0', {'mode': 'slot_choice', 'nonce': 'new'})
            prompt.assert_not_awaited()


if __name__ == '__main__':
    unittest.main()
