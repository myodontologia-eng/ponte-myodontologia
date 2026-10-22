"""Keep patient identity separate from appointment presentation."""
import re


def appointment_identity(event):
    private = (event.get('extendedProperties') or {}).get('private') or {}
    description = event.get('description') or ''
    def field(label):
        match = re.search(r'^\s*' + label + r':[ \t]*([^\r\n]*)', description, re.I | re.M)
        value = match.group(1).strip() if match else ''
        return '' if value.lower() in ('não informado', 'nao informado') else value
    name = event.get('patient_name') or private.get('patient_name') or field('Nome')
    reason = event.get('patient_reason') or private.get('patient_reason') or field('Motivo')
    summary = re.sub(r'^\[DADOS PENDENTES\]\s*', '', event.get('summary') or '').strip()
    if not name:
        # Former system-generated titles used "reason - patient".
        parts = re.split(r'\s+[-–—]\s+', summary)
        if len(parts) > 1:
            name = parts[-1].strip()
            reason = reason or ' - '.join(parts[:-1]).strip()
        else:
            name = summary
    return {'name': name or 'Consulta', 'reason': reason}


def appointment_label(event):
    identity = appointment_identity(event)
    return identity['name'] + (' — ' + identity['reason'] if identity['reason'] else '')
