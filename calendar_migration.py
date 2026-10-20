"""Idempotent, non-destructive copy from the former clinic calendar."""
import logging
import os
import copy
import hashlib
import json
from googleapiclient.errors import HttpError
from calendar_service import get_calendar_service, calendar_id

log = logging.getLogger("uvicorn.error")

def all_events(service, cid):
    items, page = [], None
    while True:
        result = service.events().list(calendarId=cid, maxResults=2500,
            showDeleted=False, singleEvents=False, pageToken=page).execute()
        items.extend(result.get("items", []))
        if len(items) > 10000:
            raise RuntimeError("Migration refused: more than 10000 events")
        page = result.get("nextPageToken")
        if not page:
            return items

def fingerprint(event):
    return json.dumps([event.get("summary", ""), event.get("description", ""),
        event.get("start"), event.get("end"), event.get("recurrence")],
        sort_keys=True, ensure_ascii=False)

def run():
    source, target = os.getenv("MIGRATE_FROM_CALENDAR_ID"), calendar_id()
    if not source or source == target:
        return
    service = get_calendar_service()
    old, new = all_events(service, source), all_events(service, target)
    by_id = {e["id"]: e for e in new}
    by_content = {}
    for e in new:
        by_content.setdefault(fingerprint(e), []).append(e)
    # Preflight all collisions before making changes.
    for e in old:
        existing = by_id.get(e["id"])
        if existing and fingerprint(existing) != fingerprint(e):
            private = existing.get("extendedProperties", {}).get("private", {})
            if private.get("migration_source_calendar") != source or private.get("migration_source_event") != e["id"]:
                raise RuntimeError("Migration refused: unrelated event ID collision")
    copied = tagged = kept = 0
    # Recurrence masters precede exceptions.
    for event in sorted(old, key=lambda e: bool(e.get("recurringEventId"))):
        provenance = {"migration_source_calendar": source, "migration_source_event": event["id"]}
        existing = by_id.get(event["id"])
        if not existing:
            matches = by_content.get(fingerprint(event), [])
            if len(matches) > 1:
                raise RuntimeError("Migration refused: ambiguous duplicate")
            existing = matches[0] if matches else None
        if existing:
            props = copy.deepcopy(existing.get("extendedProperties", {}))
            private = props.setdefault("private", {})
            if all(private.get(k) == v for k, v in provenance.items()):
                kept += 1
                continue
            if any(k in private and private[k] != v for k, v in provenance.items()):
                raise RuntimeError("Migration refused: conflicting provenance")
            private.update(provenance)
            request = service.events().patch(calendarId=target, eventId=existing["id"],
                body={"extendedProperties": props}, sendUpdates="none")
            request.headers["If-Match"] = existing["etag"]
            request.execute()
            tagged += 1
            continue
        keys = ("summary", "description", "location", "start", "end", "recurrence",
                "recurringEventId", "originalStartTime", "transparency", "visibility",
                "reminders", "colorId", "extendedProperties")
        body = {k: copy.deepcopy(event[k]) for k in keys if k in event}
        body["id"] = event["id"]
        body.setdefault("extendedProperties", {}).setdefault("private", {}).update(provenance)
        # No guests or conference notifications are created by this copy.
        created = service.events().insert(calendarId=target, body=body, sendUpdates="none").execute()
        by_id[created["id"]] = created
        by_content.setdefault(fingerprint(created), []).append(created)
        copied += 1
    # Counts only: never log patient titles, descriptions, or contact details.
    log.info("CALENDAR_MIGRATION complete source_count=%d copied=%d tagged=%d already_present=%d; source unchanged",
             len(old), copied, tagged, kept)
