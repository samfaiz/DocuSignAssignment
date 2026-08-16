import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { demoApi } from '../lib/api'
import { useTour, useTourStep } from '../lib/tour'

/**
 * Landing page for whoever is evaluating this project.
 *
 * The point is to remove every step between arriving and seeing the signing
 * ceremony work — no terminal, no hunting through a mail catcher for a passcode.
 */
export default function Demo() {
  useTourStep('demo.start')

  const navigate = useNavigate()
  const { enabled, toggle } = useTour()

  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function startCeremony() {
    setBusy(true)
    setError(null)
    try {
      const { sign_url } = await demoApi.provision()
      // Same-origin URL, so route within the SPA rather than reloading.
      navigate(new URL(sign_url).pathname + new URL(sign_url).search)
    } catch {
      setError(
        'Could not create a demo envelope. Check that the API, the queue worker and the sealing service are all running.',
      )
      setBusy(false)
    }
  }

  return (
    <div className="mx-auto max-w-3xl px-6 py-14">
      <div className="mb-2 flex items-center gap-2">
        <span className="grid h-8 w-8 place-items-center rounded bg-blue-700 text-xs font-bold text-white">
          SD
        </span>
        <h1 className="text-xl font-semibold tracking-tight">SignDesk</h1>
      </div>

      <p className="mb-8 max-w-2xl text-sm leading-relaxed text-slate-600">
        A document signing platform. Send a PDF by email, sign it in the browser,
        and get back a file sealed with a PAdES-B-LTA digital signature and a
        trusted timestamp. Pick a starting point below.
      </p>

      {error && (
        <p className="mb-6 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{error}</p>
      )}

      <div className="grid gap-4 sm:grid-cols-2">
        <section className="flex flex-col rounded-lg border border-slate-200 bg-white p-5">
          <h2 className="text-sm font-semibold">Sign a document</h2>
          <p className="mt-1 flex-1 text-[13px] leading-relaxed text-slate-600">
            Creates an agreement and drops you into the signer's shoes: passcode,
            consent, then sign by drawing, typing or uploading. No account needed —
            this half of the product deliberately has none.
          </p>
          <button
            onClick={startCeremony}
            disabled={busy}
            className="mt-4 rounded-md bg-blue-700 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-blue-800 disabled:bg-slate-300"
          >
            {busy ? 'Preparing…' : 'Start signing ceremony'}
          </button>
        </section>

        <section className="flex flex-col rounded-lg border border-slate-200 bg-white p-5">
          <h2 className="text-sm font-semibold">Send one yourself</h2>
          <p className="mt-1 flex-1 text-[13px] leading-relaxed text-slate-600">
            Sign in as the administrator to upload a PDF, place fields on the page,
            send it, and inspect the seal and audit trail a completed envelope
            leaves behind.
          </p>
          <div className="mt-4 rounded-md bg-slate-50 px-3 py-2 font-mono text-[11px] text-slate-600">
            admin@signdesk.test
            <br />
            password
          </div>
          <button
            onClick={() => navigate('/login')}
            className="mt-3 rounded-md border border-slate-300 px-4 py-2.5 text-sm font-medium transition hover:bg-slate-50"
          >
            Go to sign-in
          </button>
        </section>
      </div>

      <section className="mt-8 rounded-lg border border-slate-200 bg-white p-5">
        <h2 className="text-sm font-semibold">Worth looking at</h2>
        <ul className="mt-3 space-y-2.5 text-[13px] leading-relaxed text-slate-600">
          <li>
            <b className="font-medium text-slate-900">The seal is real.</b> A
            completed envelope is signed with PAdES-B-LTA and timestamped by
            DigiCert. Open the signed PDF in Adobe Reader, or use the Verify page.
          </li>
          <li>
            <b className="font-medium text-slate-900">Tampering is detectable.</b>{' '}
            Download a signed PDF, change one byte in a hex editor, and upload it
            to Verify. It still opens perfectly and reports as altered.
          </li>
          <li>
            <b className="font-medium text-slate-900">The audit trail is chained.</b>{' '}
            Each event's hash covers the previous one's, and the database rejects
            updates and deletes outright. The envelope page recomputes the chain on
            every load.
          </li>
          <li>
            <b className="font-medium text-slate-900">Mail is captured locally.</b>{' '}
            Every message the system sends is visible at{' '}
            <a
              href="http://localhost:8025"
              className="text-blue-700 underline"
              target="_blank"
              rel="noreferrer"
            >
              localhost:8025
            </a>
            .
          </li>
        </ul>
      </section>

      {!enabled && (
        <p className="mt-8 text-[13px] text-slate-500">
          Turn on the{' '}
          <button onClick={toggle} className="font-medium text-blue-700 underline">
            guided tour
          </button>{' '}
          to see the reasoning behind each step as you reach it.
        </p>
      )}
    </div>
  )
}
