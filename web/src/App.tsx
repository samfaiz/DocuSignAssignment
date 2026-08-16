import { BrowserRouter, Link, Navigate, Route, Routes, useNavigate } from 'react-router-dom'
import TourPanel, { TourInset } from './components/TourPanel'
import { adminToken, api } from './lib/api'
import { TourProvider } from './lib/tour'
import Demo from './pages/Demo'
import EnvelopeDetail from './pages/EnvelopeDetail'
import Envelopes from './pages/Envelopes'
import Login from './pages/Login'
import NewEnvelope from './pages/NewEnvelope'
import Sign from './pages/Sign'
import Verify from './pages/Verify'

function RequireAdmin({ children }: { children: React.ReactNode }) {
  return adminToken.get() ? <>{children}</> : <Navigate to="/login" replace />
}

function AdminShell({ children }: { children: React.ReactNode }) {
  const navigate = useNavigate()

  async function signOut() {
    try {
      await api.logout()
    } finally {
      // Clear locally even if the server call failed — leaving a token behind
      // because logout 500'd is the wrong failure mode.
      adminToken.clear()
      navigate('/login')
    }
  }

  return (
    <div className="min-h-full">
      <header className="border-b border-slate-200 bg-white">
        <div className="mx-auto flex max-w-6xl items-center justify-between px-6 py-3">
          <Link to="/envelopes" className="flex items-center gap-2">
            <span className="grid h-7 w-7 place-items-center rounded bg-blue-700 text-xs font-bold text-white">
              SD
            </span>
            <span className="text-sm font-semibold tracking-tight">SignDesk</span>
          </Link>

          <nav className="flex items-center gap-5 text-sm">
            <Link to="/envelopes" className="text-slate-600 hover:text-slate-900">
              Envelopes
            </Link>
            <Link to="/envelopes/new" className="text-slate-600 hover:text-slate-900">
              New
            </Link>
            <Link to="/verify" className="text-slate-600 hover:text-slate-900">
              Verify
            </Link>
            <button
              onClick={signOut}
              className="text-slate-500 underline-offset-2 hover:text-slate-900 hover:underline"
            >
              Sign out
            </button>
          </nav>
        </div>
      </header>

      <main className="mx-auto max-w-6xl px-6 py-8">{children}</main>
    </div>
  )
}

export default function App() {
  return (
    <TourProvider>
      <BrowserRouter>
        <TourInset>
          <AppRoutes />
        </TourInset>
        <TourPanel />
      </BrowserRouter>
    </TourProvider>
  )
}

function AppRoutes() {
  return (
    <Routes>
        {/* Reviewers land here: no account, one click into the ceremony. */}
        <Route path="/demo" element={<Demo />} />
        <Route path="/login" element={<Login />} />

        {/* The signing ceremony is deliberately outside the admin shell: signers
            have no account here, and should see no admin navigation. */}
        <Route path="/sign/:uuid" element={<Sign />} />

        <Route
          path="/envelopes"
          element={
            <RequireAdmin>
              <AdminShell>
                <Envelopes />
              </AdminShell>
            </RequireAdmin>
          }
        />
        <Route
          path="/envelopes/new"
          element={
            <RequireAdmin>
              <AdminShell>
                <NewEnvelope />
              </AdminShell>
            </RequireAdmin>
          }
        />
        <Route
          path="/envelopes/:uuid"
          element={
            <RequireAdmin>
              <AdminShell>
                <EnvelopeDetail />
              </AdminShell>
            </RequireAdmin>
          }
        />
        <Route
          path="/verify"
          element={
            <RequireAdmin>
              <AdminShell>
                <Verify />
              </AdminShell>
            </RequireAdmin>
          }
        />

        {/* Signed-in admins go to their work; everyone else meets the demo page. */}
        <Route
          path="*"
          element={<Navigate to={adminToken.get() ? '/envelopes' : '/demo'} replace />}
        />
    </Routes>
  )
}
