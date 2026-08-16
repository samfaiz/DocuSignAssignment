import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api, type EnvelopeSummary } from '../lib/api'
import { useTourStep } from '../lib/tour'

const STATUS_STYLES: Record<string, string> = {
  draft: 'bg-slate-100 text-slate-700',
  sent: 'bg-blue-50 text-blue-700',
  in_progress: 'bg-amber-50 text-amber-700',
  completed: 'bg-emerald-50 text-emerald-700',
  declined: 'bg-red-50 text-red-700',
  voided: 'bg-slate-100 text-slate-500',
  expired: 'bg-slate-100 text-slate-500',
}

export function StatusBadge({ status }: { status: string }) {
  return (
    <span
      className={`inline-block rounded-full px-2 py-0.5 text-xs font-medium ${
        STATUS_STYLES[status] ?? 'bg-slate-100 text-slate-700'
      }`}
    >
      {status.replace('_', ' ')}
    </span>
  )
}

export default function Envelopes() {
  useTourStep('admin.envelopes')

  const [envelopes, setEnvelopes] = useState<EnvelopeSummary[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    api
      .envelopes()
      .then((page) => setEnvelopes(page.data))
      .catch(() => setError('Could not load envelopes.'))
      .finally(() => setLoading(false))
  }, [])

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold tracking-tight">Envelopes</h1>
          <p className="text-sm text-slate-500">Documents sent for signature.</p>
        </div>
        <Link
          to="/envelopes/new"
          className="rounded-md bg-blue-700 px-4 py-2 text-sm font-medium text-white hover:bg-blue-800"
        >
          New envelope
        </Link>
      </div>

      {loading && <p className="text-sm text-slate-500">Loading…</p>}
      {error && <p className="rounded-md bg-red-50 p-4 text-sm text-red-700">{error}</p>}

      {!loading && !error && envelopes.length === 0 && (
        <div className="rounded-lg border border-dashed border-slate-300 bg-white p-12 text-center">
          <p className="text-sm text-slate-600">Nothing sent yet.</p>
          <Link
            to="/envelopes/new"
            className="mt-3 inline-block text-sm font-medium text-blue-700 hover:underline"
          >
            Send your first document
          </Link>
        </div>
      )}

      {envelopes.length > 0 && (
        <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
          <table className="w-full text-sm">
            <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
              <tr>
                <th className="px-4 py-3 font-medium">Subject</th>
                <th className="px-4 py-3 font-medium">Recipients</th>
                <th className="px-4 py-3 font-medium">Status</th>
                <th className="px-4 py-3 font-medium">Created</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {envelopes.map((envelope) => (
                <tr key={envelope.uuid} className="hover:bg-slate-50">
                  <td className="px-4 py-3">
                    <Link
                      to={`/envelopes/${envelope.uuid}`}
                      className="font-medium text-blue-700 hover:underline"
                    >
                      {envelope.subject}
                    </Link>
                    <div className="text-xs text-slate-500">
                      {envelope.document?.filename}
                    </div>
                  </td>
                  <td className="px-4 py-3 text-slate-600">
                    {(envelope.recipients ?? []).map((r) => (
                      <div key={r.email} className="text-xs">
                        {r.name}{' '}
                        <span className={r.signed_at ? 'text-emerald-600' : 'text-slate-400'}>
                          {r.signed_at ? '✓ signed' : `· ${r.status}`}
                        </span>
                      </div>
                    ))}
                  </td>
                  <td className="px-4 py-3">
                    <StatusBadge status={envelope.status} />
                  </td>
                  <td className="px-4 py-3 text-xs text-slate-500">
                    {new Date(envelope.created_at).toLocaleString()}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}
