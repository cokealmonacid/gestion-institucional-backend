# Tree Directory Lifecycle

## Regla MVP

El ciclo de vida de nodos del tree-directory usa desactivacion logica mediante el campo `active`.

- `DELETE /api/v1/institution/tree-directory/{node_id}` desactiva solo el nodo seleccionado.
- `PATCH /api/v1/institution/tree-directory/{node_id}/activate` reactiva solo el nodo seleccionado.
- No existe eliminacion fisica de nodos.
- No se desactivan ni reactivan hijos o descendientes.
- Todas las operaciones se limitan a la institucion del usuario autenticado.

## Motivo de no usar cascada

El modelo actual solo tiene el campo `active`. Con ese unico estado no se puede distinguir entre:

- un nodo desactivado directamente por una accion del usuario;
- un nodo oculto porque alguno de sus padres esta inactivo.

Por esa razon, aplicar cascada podria destruir informacion de estado propio de los descendientes. El MVP conserva el estado individual de cada nodo.

## Rama oculta

Cuando un nodo padre queda inactivo, la rama queda oculta en la navegacion normal porque los endpoints publicos del tree-directory solo devuelven nodos activos y solo permiten navegar desde padres activos.

Los descendientes conservan su valor original de `active`. Un hijo puede seguir activo aunque su padre este inactivo, pero no sera alcanzable desde la navegacion normal hasta que el padre sea reactivado.

## Activate

`PATCH /api/v1/institution/tree-directory/{node_id}/activate` cambia a `active = true` solo el nodo seleccionado.

El endpoint no modifica descendientes. Si los hijos ya estaban activos, volveran a ser visibles cuando el padre activo permita navegar hacia ellos. Si algun hijo estaba inactivo por su propio estado, seguira inactivo.

## Limitacion conocida

El campo `active` mezcla el estado propio del nodo con la visibilidad efectiva en el arbol. Esto es suficiente para el MVP, pero no representa de forma explicita estados heredados.

## Evolucion futura

Si se necesita cascada real o auditoria de lifecycle, conviene agregar un modelo mas explicito. Algunas opciones:

- `disabled_by_parent`
- `archived_at`
- `deactivated_at`
- un modelo lifecycle separado que registre motivo, actor y origen del cambio
