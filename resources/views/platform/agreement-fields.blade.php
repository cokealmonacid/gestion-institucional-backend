<label>Plan de origen
    <select x-model="agreement.plan_id" @change="selectPlan" required>
        <option value="">Seleccionar plan</option>
        <template x-if="screen === 'agreement' && detail?.current_contract"><option :value="detail.current_contract.conditions.source_plan.id" x-text="detail.current_contract.conditions.source_plan.name + ' · instantánea del convenio actual'"></option></template>
        <template x-for="plan in planOptions.filter(p => screen !== 'agreement' || p.id !== detail?.current_contract?.conditions.source_plan.id)" :key="plan.id"><option :value="plan.id" x-text="plan.name + ' · revisión ' + plan.revision"></option></template>
    </select>
</label>
<p class="muted">El plan es una referencia de catálogo. Debes definir explícitamente las condiciones del convenio. Cero cupos bloquea admisiones; un límite vacío significa sin límite.</p>
<div class="grid">
    <label>Tipo de acuerdo<select x-model="agreement.agreement_kind"><option value="paid">Pagado</option><option value="free_trial">Prueba gratuita</option></select></label>
    <label>Estado del acuerdo<select x-model="agreement.lifecycle"><option value="agreed">Acordado</option><option value="terminated">Terminado</option></select></label>
    <label>Precio acordado en CLP<input x-model="agreement.price_clp" inputmode="numeric" pattern="[0-9]{1,18}" maxlength="18" required><small>Importe entero. No representa una confirmación de pago.</small></label>
    <label>Tratamiento de impuestos<select x-model="agreement.tax_treatment"><option value="inclusive">Incluidos en el precio</option><option value="exclusive">Excluidos del precio</option></select></label>
    <label>Periodicidad<select x-model="agreement.periodicity"><option value="monthly">Mensual</option><option value="annual">Anual</option><option value="one_time_pilot">Piloto, pago único</option></select></label>
    <label>Inicio del convenio<input type="date" x-model="agreement.starts_on" required></label>
    <label>Término del convenio (opcional)<input type="date" x-model="agreement.ends_on" :min="agreement.starts_on"></label>
</div>
<h3>Límites negociados por rol</h3>
<div class="grid"><template x-for="role in roles" :key="role"><div>
    <label><span x-text="label(role)"></span><input type="number" x-model="agreement.limits[role]" min="0" max="2147483647" step="1" placeholder="Sin límite"></label>
    <p class="muted">Base del plan: <span x-text="quota(sourcePlan()?.limits[role])"></span>.</p>
    <label x-show="sourcePlan() && (agreement.limits[role] === '' || agreement.limits[role] === null ? null : Number(agreement.limits[role])) !== sourcePlan()?.limits[role]">Motivo de la excepción de límite<textarea x-model="agreement.limit_reasons[role]" maxlength="1000"></textarea></label>
</div></template></div>
<label><input type="checkbox" x-model="agreement.has_trial">Incluir período de prueba o piloto</label>
<div class="grid" x-show="agreement.has_trial">
    <label>Modalidad<select x-model="agreement.trial_type"><option value="trial">Prueba</option><option value="pilot">Piloto</option></select></label>
    <label>Inicio de prueba o piloto<input type="date" x-model="agreement.trial_start" :required="agreement.has_trial" :min="agreement.starts_on" :disabled="!agreement.has_trial"></label>
    <label>Término de prueba o piloto<input type="date" x-model="agreement.trial_end" :required="agreement.has_trial" :min="agreement.trial_start" :disabled="!agreement.has_trial"></label>
</div>
<p class="muted">Una prueba gratuita requiere precio cero y fechas de prueba. Un pago único de piloto requiere fechas de piloto dentro del convenio.</p>
<h3>Otras excepciones negociadas</h3>
<p class="muted">Registra acuerdos especiales de precio, fechas o periodicidad con su motivo. Los valores se tomarán del formulario revisado.</p>
<template x-for="(item, index) in agreement.extra_exceptions" :key="index"><div class="grid">
    <label>Condición<select x-model="item.condition"><option value="price_clp">Precio CLP</option><option value="periodicity">Periodicidad</option><option value="starts_on">Inicio</option><option value="ends_on">Término</option><option value="tax_treatment">Impuestos</option></select></label>
    <label>Motivo de excepción<input x-model="item.reason" required maxlength="1000"></label>
    <div><button type="button" class="secondary" @click="agreement.extra_exceptions.splice(index, 1)">Quitar excepción</button></div>
</div></template>
<button type="button" class="secondary" @click="agreement.extra_exceptions.push({ condition: 'price_clp', reason: '' })" :disabled="agreement.extra_exceptions.length >= 17">Agregar excepción</button>
<label>Motivo del acuerdo o enmienda<textarea x-model="agreement.reason" required maxlength="1000"></textarea></label>
