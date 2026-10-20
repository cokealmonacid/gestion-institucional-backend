<label>Tipo de cliente
    <select x-model="{{ $model }}.type"><option value="">Sin configurar</option><option value="natural_person">Persona natural</option><option value="legal_entity">Persona jurídica</option></select>
</label>
<label>Nombre o razón social<input x-model="{{ $model }}.name" maxlength="255" :required="Boolean({{ $model }}.type)"></label>
<label>RUT del cliente<input x-model="{{ $model }}.rut" maxlength="32" placeholder="12.345.678-5" :required="Boolean({{ $model }}.type)"><small>Se admite formato con puntos; el servidor verifica el dígito y normaliza el RUT.</small></label>
