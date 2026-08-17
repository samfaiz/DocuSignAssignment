import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { api, type ChainStatus, type EnvelopeDetail as Detail } from '../lib/api'
import SignerEvidence from '../components/SignerEvidence'
import { useTourStep } from '../lib/tour'
import { StatusBadge } from './Envelopes'

export default function EnvelopeDetail() {
  useTourStep('admin.detail')

  const { uuid = '' } = useParams()
  const [envelope, setEnvelope] = useState<Detail | null>(null)
  const [chain, setChain] = useState<ChainStatus | null>(null)
  const [error, setError] = useState<string | null>(null)

  async function refresh() {
    try {
      const data = await api.envelope(uuid)
      setEnvelope(data.envelope)
      setChain(data.audit_chain)
    } catch {
      setError('Could not load this envelope.')
    }
  }

  useEffect(() => {
    void refresh()
    // Sealing happens on a queue, so poll until the sealed document appears.
    const timer = setInterval(refresh, 5000)
    return () => clearInterval(timer)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [uuid])

  async function download() {
    const blob = await api.sealedBlob(uuid)
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `signed-${envelope?.document.filename ?? 'document.pdf'}`
    link.click()
    URL.revokeObjectURL(url)
  }

  if (error) return <p className="rounded-md bg-red-50 p-4 text-sm text-red-700">{error}</p>
  if (!envelope) return <p className="text-sm text-slate-500">Loading…</p>

  const sealed = envelope.sealed_document

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <div className="mb-1 flex items-center gap-3">
            <h1 className="text-xl font-semibold tracking-tight">{envelope.subject}</h1>
            <StatusBadge status={envelope.status} />
          </div>
          <p className="text-sm text-slate-500">
            {envelope.document.filename} · {envelope.document.page_count} page
            {envelope.document.page_count === 1 ? '' : 's'}
          </p>
        </div>

        {sealed && (
          <button
            onClick={download}
            className="rounded-md bg-blue-700 px-4 py-2 text-sm font-medium text-white hover:bg-blue-800"
          >
            Download signed PDF
          </button>
        )}
      </div>

      {/* The two integrity claims, shown as facts rather than a green tick. */}
      <div className="grid gap-4 sm:grid-cols-2">
        <Panel title="Cryptographic seal">
          {sealed ? (
            <dl className="space-y-1.5 text-sm">
              <Row label="Standard" value={sealed.pades_level} mono />
              <Row label="Timestamp authority" value={sealed.tsa_url ?? '—'} />
              <Row label="Certificate" value={sealed.certificate_subject ?? '—'} />
              <Row label="SHA-256 (sealed)" value={sealed.sha256_sealed} mono wrap />
              <Row
                label="Sealed at"
                value={new Date(sealed.sealed_at).toLocaleString()}
              />
              {sealed.warnings.length > 0 && (
                <p className="mt-2 rounded bg-amber-50 px-2 py-1.5 text-xs text-amber-800">
                  {sealed.warnings.join('; ')}
                </p>
              )}
            </dl>
          ) : envelope.status === 'completed' ? (
            <p className="text-sm text-slate-500">
              Sealing in progress — this runs on a queue and involves a timestamp
              authority round trip.
            </p>
          ) : (
            <p className="text-sm text-slate-500">Not sealed yet.</p>
          )}
        </Panel>

        <Panel title="Audit chain">
          {chain ? (
            <div className="text-sm">
              <p
                className={
                  chain.valid ? 'font-medium text-emerald-700' : 'font-medium text-red-700'
                }
              >
                {chain.valid
                  ? `Verified — ${chain.count} events, unbroken`
                  : `BROKEN at event ${chain.broken_at}`}
              </p>
              {!chain.valid && (
                <p className="mt-1 text-xs text-red-600">{chain.reason}</p>
              )}
              <p className="mt-2 text-xs text-slate-500">
                Every event's hash covers the previous one's, recomputed on each load.
              </p>
            </div>
          ) : (
            <p className="text-sm text-slate-500">—</p>
          )}
        </Panel>
      </div>

      <Panel title="Recipients">
        <div className="divide-y divide-slate-100">
          {envelope.recipients.map((recipient) => (
            <div key={recipient.id} className="py-3 first:pt-0 last:pb-0">
              <div className="flex flex-wrap items-baseline justify-between gap-2">
                <div>
                  <p className="text-sm font-medium text-slate-900">{recipient.name}</p>
                  <p className="text-xs text-slate-500">{recipient.email}</p>
                </div>
                <div className="text-right text-xs">
                  {recipient.signed_at ? (
                    <span className="text-emerald-700">
                      Signed {new Date(recipient.signed_at).toLocaleString()}
                    </span>
                  ) : (
                    <span className="text-slate-400">{recipient.status}</span>
                  )}
                  <p className="text-slate-500">
                    {recipient.auth_method ?? 'Not yet verified'}
                  </p>
                </div>
              </div>

              <SignerEvidence envelopeUuid={uuid} recipient={recipient} />
            </div>
          ))}
        </div>
      </Panel>

      <Panel title={`Audit trail (${envelope.audit_events.length} events)`}>
        <div className="max-h-96 overflow-y-auto">
          <table className="w-full text-xs">
            <thead className="sticky top-0 bg-white text-left uppercase tracking-wide text-slate-500">
              <tr>
                <th className="pb-2 font-medium">#</th>
                <th className="pb-2 font-medium">Event</th>
                <th className="pb-2 font-medium">Actor</th>
                <th className="pb-2 font-medium">When (UTC)</th>
                <th className="pb-2 font-medium">IP</th>
                <th className="pb-2 font-medium">Hash</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 font-mono">
              {envelope.audit_events.map((event) => (
                <tr key={event.seq}>
                  <td className="py-1.5 text-slate-400">{event.seq}</td>
                  <td className="py-1.5 font-sans text-slate-800">{event.type}</td>
                  <td className="py-1.5 font-sans text-slate-600">{event.actor ?? '—'}</td>
                  <td className="py-1.5 text-slate-500">
                    {event.occurred_at.replace('T', ' ').slice(0, 19)}
                  </td>
                  <td className="py-1.5 text-slate-500">{event.ip ?? '—'}</td>
                  <td className="py-1.5 text-slate-400">{event.hash.slice(0, 12)}…</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Panel>
    </div>
  )
}

function Panel({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <section className="rounded-lg border border-slate-200 bg-white p-5">
      <h2 className="mb-3 text-sm font-semibold">{title}</h2>
      {children}
    </section>
  )
}

function Row({
  label,
  value,
  mono,
  wrap,
}: {
  label: string
  value: string
  mono?: boolean
  wrap?: boolean
}) {
  return (
    <div className="flex gap-3">
      <dt className="w-40 shrink-0 text-slate-500">{label}</dt>
      <dd
        className={`text-slate-800 ${mono ? 'font-mono text-xs' : ''} ${
          wrap ? 'break-all' : 'truncate'
        }`}
      >
        {value}
      </dd>
    </div>
  )
}
