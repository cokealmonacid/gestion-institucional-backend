const pendingKey = 'acervo.platform.role-change.v1';

export function rolePanel(storage = globalThis.sessionStorage) {
    let pending = null;
    try {
        const value = JSON.parse(storage?.getItem(pendingKey) || 'null');
        if (value && /^[a-f0-9-]{36}$/i.test(value.user_id) && /^[a-f0-9-]{36}$/i.test(value.institution_id)) pending = value;
    } catch { /* Storage is optional; server revisions still prevent duplicate writes. */ }
    return {
        roleUser: pending, roleDestination: '', roleReason: '', roleImpact: null,
        roleConfirmation: false, roleUnknown: Boolean(pending), roleResult: '', roleAction: 'role',
        roleQuotaPreview() {
            if (!this.roleUser?.roles || !this.detail?.quotas?.roles) return [];
            const user = this.roleUser;
            const finalRoles = this.roleAction === 'role' ? (this.roleDestination ? [this.roleDestination] : user.roles) : user.roles;
            const active = this.roleAction === 'access' ? !user.active : user.active;
            return ['admin', 'editor', 'reader'].map(role => {
                const current = this.detail.quotas.roles[role];
                const delta = Number(active && finalRoles.includes(role)) - Number(user.active && user.roles.includes(role));
                return { role, before: current.used, after: current.used + delta, limit: current.limit,
                    blocked: delta > 0 && current.limit !== null && current.used + delta > current.limit };
            });
        },
        async openRoleChange(user, action = 'role') {
            if (this.roleUnknown) return;
            await this.run(async () => {
                await this.fetchDetail(user.institution_id);
                const result = await this.client.request(`users?user_id=${user.user_id}&institution_id=${user.institution_id}`);
                this.roleUser = result.items[0];
                if (!this.roleUser) throw new Error('Cuenta no disponible');
                this.roleAction = action; this.roleDestination = ''; this.roleReason = ''; this.roleImpact = null;
                this.roleConfirmation = false; this.roleResult = ''; this.screen = 'role-change';
            });
        },
        async inspectRoleImpact() {
            this.roleConfirmation = false; this.roleImpact = null;
            await this.run(async () => {
                this.roleImpact = await this.client.request(`institutions/${this.roleUser.institution_id}/users/${this.roleUser.user_id}/role-impact?role=${this.roleDestination}`);
            });
        },
        async submitRoleChange() {
            if (this.roleUnknown || !this.roleConfirmation || !this.roleReason.trim()) return;
            if (this.roleAction === 'role' && (!this.roleImpact || this.roleImpact.access_revision !== this.roleUser.access_revision)) return;
            await this.run(async () => {
                const user = this.roleUser;
                const body = { expected_user_revision: user.access_revision, reason: this.roleReason, confirmation: true };
                if (this.roleAction === 'role') {
                    body.role = this.roleDestination;
                    if (this.roleImpact.confirmation_required) {
                        body.confirm_responsibility_removal = true; body.role_impact_token = this.roleImpact.impact_token;
                    }
                } else body.active = !user.active;
                // Retain only lookup IDs across reload/auth expiry, never credentials or a retry body.
                try { storage?.setItem(pendingKey, JSON.stringify({ user_id: user.user_id, institution_id: user.institution_id })); } catch { /* optional */ }
                let result;
                try {
                    result = await this.client.request(`institutions/${user.institution_id}/users/${user.user_id}/${this.roleAction}`, { method: 'POST', body });
                } catch (error) {
                    if (error.uncertain || error.code === 'SESSION_CHANGED') {
                        this.roleUnknown = true;
                        this.roleUser = { user_id: user.user_id, institution_id: user.institution_id };
                    }
                    else {
                        try { storage?.removeItem(pendingKey); } catch { /* optional */ }
                        this.roleImpact = null; this.roleConfirmation = false;
                    }
                    throw error;
                }
                // The mutation is confirmed even if a later refresh fails.
                try { storage?.removeItem(pendingKey); } catch { /* optional */ }
                this.roleUser = { ...user, ...result }; this.roleImpact = null; this.roleConfirmation = false;
                this.roleResult = 'Cambio confirmado. Las sesiones y enlaces anteriores fueron revocados si cambió el acceso.';
                await this.refreshRoleViews();
            });
        },
        async refreshRoleViews() {
            const user = this.roleUser;
            await this.fetchDetail(user.institution_id);
            await this.loadAdmins(); await this.loadUsers(this.users.page);
            const result = await this.client.request(`users?user_id=${user.user_id}&institution_id=${user.institution_id}`);
            this.roleUser = result.items[0]; this.userAccess = this.roleUser;
        },
        async resolveRoleUnknown() {
            await this.run(async () => {
                await this.refreshRoleViews();
                try { storage?.removeItem(pendingKey); } catch { /* optional */ }
                this.roleUnknown = false; this.roleImpact = null; this.roleConfirmation = false;
                this.screen = 'role-change';
                this.roleResult = 'Estado actual consultado; no acredita por sí solo quién aplicó un cambio. Revisa la auditoría antes de preparar otra acción.';
            });
        },
    };
}
