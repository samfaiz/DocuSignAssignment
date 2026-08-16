import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { adminToken, api, ApiError } from '../lib/api'
import { useTourStep } from '../lib/tour'

export default function Login() {
  useTourStep('admin.login')

  const navigate = useNavigate()
  const [email, setEmail] = useState('admin@signdesk.test')
  const [password, setPassword] = useState('password')
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  async function submit(event: React.FormEvent) {
    event.preventDefault()
    setBusy(true)
    setError(null)
    try {
      const { token } = await api.login(email, password)
      adminToken.set(token)
      navigate('/envelopes')
    } catch (e) {
      // The server answers identically for an unknown account and a wrong
      // password, so this message stays vague on purpose.
      setError(
        e instanceof ApiError
          ? (e.fieldError('email') ?? e.message)
          : 'Could not sign in.',
      )
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="grid min-h-full place-items-center px-6">
      <div className="w-full max-w-sm">
        <div className="mb-6 flex items-center gap-2">
          <span className="grid h-9 w-9 place-items-center rounded bg-blue-700 text-sm font-bold text-white">
            SD
          </span>
          <div>
            <h1 className="text-lg font-semibold tracking-tight">SignDesk</h1>
            <p className="text-xs text-slate-500">Administrator sign-in</p>
          </div>
        </div>

        <form
          onSubmit={submit}
          className="space-y-4 rounded-lg border border-slate-200 bg-white p-6"
        >
          <div>
            <label className="mb-1 block text-sm font-medium text-slate-700">Email</label>
            <input
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              required
              autoComplete="username"
              className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-blue-600 focus:ring-2 focus:ring-blue-100"
            />
          </div>

          <div>
            <label className="mb-1 block text-sm font-medium text-slate-700">Password</label>
            <input
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              required
              autoComplete="current-password"
              className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm outline-none focus:border-blue-600 focus:ring-2 focus:ring-blue-100"
            />
          </div>

          {error && (
            <p className="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700">{error}</p>
          )}

          <button
            type="submit"
            disabled={busy}
            className="w-full rounded-md bg-blue-700 px-4 py-2 text-sm font-medium text-white transition hover:bg-blue-800 disabled:bg-slate-300"
          >
            {busy ? 'Signing in…' : 'Sign in'}
          </button>

          <p className="text-center text-xs text-slate-500">
            Seeded credentials: admin@signdesk.test / password
          </p>
        </form>
      </div>
    </div>
  )
}
