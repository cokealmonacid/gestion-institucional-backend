<p>Hola, {{ $recipientName }}:</p>
<p>Te han invitado a acceder a Acervo para {{ $institutionName }}.</p>
<p>Para acreditar tu correo y establecer tu contraseña, abre este enlace:</p>
<p><a href="{{ $activationUrl }}">Activar mi acceso</a></p>
<p>El enlace caduca en {{ $expiresInHours }} horas y sólo puede utilizarse una vez.</p>
<p>Si no esperabas esta invitación, puedes ignorar este mensaje.</p>
