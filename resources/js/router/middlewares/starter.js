/**
 * Allows an authenticated user to access the main view only if he owns at least one twofaccount.
 * Push to the starter view otherwise.
 */
export default async function starter({ stores }) {
    const { twofaccounts } = stores

    if (twofaccounts.isEmpty) {
        // E14: a rejected fetch used to leave navigation pending forever
        // (blank screen) — fall back to the starter view, which retries the
        // fetch itself, instead of leaving navigation unresolved.
        try {
            await twofaccounts.fetch()
        }
        catch (error) {
            return { name: 'start' }
        }

        if (twofaccounts.isEmpty) {
            return { name: 'start' }
        }
    }

    return true
}
