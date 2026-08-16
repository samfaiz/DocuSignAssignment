import { useState } from 'react'
import { api, ApiError, type VerificationResult } from '../lib/api'
import { useTourStep } from '../lib/tour'

/**
 * Upload any PDF and check its signature.
 *
 * Integrity and trust are reported separately on purpose: "these bytes have not
 * changed since signing" and "the signer's certificate chains to something we
 * trust" fail for entirely different reasons, and collapsing them into one
 * verdict hides which one went wrong.
 */
export default function Verify() {
  useTourStep('admin.verify')

  const [result, setResult] = useState<VerificationResult | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [filename, setFilename] = useState<string | null>(null)

  async function check(event: React.ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0]
    if (!file) return

    setBusy(true)
    setError(null)
    setResult(null)
    setFilename(file.name)

    try {
      setResult(await api.verify(file))
    } catch (e) {
      setError(
        e instanceof ApiError ? (e.fieldError('file') ?? e.message) : 'Verification failed.',
      )
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="mx-auto max-w-2xl">
      <h1 className="mb-1 text-xl font-semibold tracking-tight">Verify a document</h1>
      <p className="mb-6 text-sm text-slate-500">
        Check whether a signed PDF has been altered since it was sealed. Nothing you
        upload here is stored.
      </p>

      <div className="rounded-lg border border-dashed border-slate-300 bg-white p-10 text-center">
        <label className="cursor-pointer">
          <span className="rounded-md bg-blue-700 px-4 py-2 text-sm font-medium text-white hover:bg-blue-800">
            {busy ? 'Checking…' : 'Choose a PDF'}
          </span>
          <input
            type="file"
            accept="application/pdf,.pdf"
            className="hidden"
            onChange={check}
            disabled={busy}
          />
        </label>
        {filename && <p className="mt-3 text-xs text-slate-500">{filename}</p>}
      </div>

      {error && (
        <p className="mt-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{error}</p>
      )}

      {result && (
        <div className="mt-6 space-y-4">
          <div
            className={`rounded-lg border p-5 ${
              result.intact && result.trusted
                ? 'border-emerald-200 bg-emerald-50'
                : result.signed
                  ? 'border-red-200 bg-red-50'
                  : 'border-slate-200 bg-slate-50'
            }`}
          >
            <p
              className={`text-sm font-medium ${
                result.intact && result.trusted
                  ? 'text-emerald-800'
                  : result.signed
                    ? 'text-red-800'
                    : 'text-slate-700'
              }`}
            >
              {result.summary}
            </p>

            <div className="mt-3 flex gap-6 text-xs">
              <Flag label="Signed" ok={result.signed} />
              <Flag label="Unaltered" ok={result.intact} />
              <Flag label="Certificate trusted" ok={result.trusted} />
            </div>
          </div>

          <section className="rounded-lg border border-slate-200 bg-white p-5">
            <h2 className="mb-3 text-sm font-semibold">Details</h2>
            <p className="mb-3 break-all font-mono text-[11px] text-slate-500">
              SHA-256 {result.report.sha256}
            </p>
            <p className="mb-3 text-xs text-slate-500">
              {result.report.signature_count} signature
              {result.report.signature_count === 1 ? '' : 's'} ·{' '}
              {result.report.revisions ?? '—'} incremental revisions
            </p>

            <div className="space-y-3">
              {result.report.signatures.map((signature) => (
                <div
                  key={signature.field_name}
                  className="rounded-md border border-slate-200 p-3 text-xs"
                >
                  <p className="mb-1 font-medium text-slate-800">
                    {signature.kind === 'document_timestamp'
                      ? 'Document timestamp'
                      : 'Signature'}{' '}
                    <span className="font-normal text-slate-400">
                      ({signature.field_name})
                    </span>
                  </p>
                  {signature.error ? (
                    <p className="text-red-600">{signature.error}</p>
                  ) : (
                    <>
                      <p className="text-slate-600">{signature.signer}</p>
                      <p className="mt-1 text-slate-500">
                        intact: {String(signature.intact)} · trusted:{' '}
                        {String(signature.trusted)} ·{' '}
                        {signature.coverage?.split('.').pop()}
                      </p>
                    </>
                  )}
                </div>
              ))}
            </div>
          </section>
        </div>
      )}
    </div>
  )
}

function Flag({ label, ok }: { label: string; ok: boolean }) {
  return (
    <span className={ok ? 'text-emerald-700' : 'text-red-700'}>
      {ok ? '✓' : '✗'} {label}
    </span>
  )
}
