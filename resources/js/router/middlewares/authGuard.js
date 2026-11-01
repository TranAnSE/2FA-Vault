import authService from '@/services/authService'

export default async function authGuard({ stores }) {
    const { user } = stores

    // No authenticated user on the front-end side, we try to
    // get an active user from the back-end side
    if (! user.isAuthenticated) {
        await authService.getCurrentUser({ returnError: true }).then(async (response) => {
            await user.loginAs(response.data)
        })
        .catch(error => {
            // nothing to do
        })
    }

    if (! user.isAuthenticated) {
        return { name: 'login' }
    }

    return true
}
