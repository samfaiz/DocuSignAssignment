import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import PdfViewer, { type PageBox } from '../components/PdfViewer'
import {
  api,
  ApiError,
  type DocumentRecord,
  type FieldType,
  type PlacedField,
  type RecipientInput,
} from '../lib/api'
import { useTourStep } from '../lib/tour'

/** Default box size for a newly dropped field, as a fraction of the page. */
const DEFAULTS: Record<FieldType, { w: number; h: number }> = {
  signature: { w: 0.26, h: 0.055 },
  initial: { w: 0.09, h: 0.045 },
  date: { w: 0.2, h: 0.024 },
  text: { w: 0.24, h: 0.026 },
  checkbox: { w: 0.03, h: 0.018 },
}

const RECIPIENT_COLOURS = [
  'rgba(29,78,216,0.14)|#1d4ed8',
  'rgba(180,83,9,0.14)|#b45309',
  'rgba(15,118,110,0.14)|#0f766e',
  'rgba(157,23,77,0.14)|#9d174d',
]

export default function NewEnvelope() {
  useTourStep('admin.new')

  const navigate = useNavigate()

  const [document, setDocument] = useState<DocumentRecord | null>(null)
  const [pdfData, setPdfData] = useState<ArrayBuffer | null>(null)
  const [uploading, setUploading] = useState(false)

  const [subject, setSubject] = useState('')
  const [message, setMessage] = useState('')
  const [expiresInDays, setExpiresInDays] = useState(30)

  const [recipients, setRecipients] = useState<RecipientInput[]>([{ name: '', email: '' }])
  const [activeRecipient, setActiveRecipient] = useState(0)
  const [activeType, setActiveType] = useState<FieldType>('signature')

  const [fields, setFields] = useState<PlacedField[]>([])
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  /** Shared by both entry points: fetch the stored PDF and render it. */
  async function adoptDocument(record: DocumentRecord, suggestedSubject: string) {
    setDocument(record)
    if (!subject) setSubject(suggestedSubject.replace(/\.pdf$/i, ''))

    const blob = await api.documentBlob(record.id)
    setPdfData(await blob.arrayBuffer())
  }

  async function upload(event: React.ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0]
    if (!file) return

    setUploading(true)
    setError(null)
    try {
      const { document: record } = await api.uploadDocument(file)
      await adoptDocument(record, file.name)
    } catch (e) {
      setError(
        e instanceof ApiError ? (e.fieldError('file') ?? e.message) : 'Upload failed.',
      )
    } finally {
      setUploading(false)
    }
  }

  async function useSample() {
    setUploading(true)
    setError(null)
    try {
      const { document: record } = await api.useSampleDocument()
      await adoptDocument(record, 'Consulting Services Agreement')
    } catch (e) {
      setError(
        e instanceof ApiError ? e.message : 'Could not load the sample document.',
      )
    } finally {
      setUploading(false)
    }
  }

  /** Place a field where the admin clicked, centred on the click point. */
  function placeField(page: number, xNorm: number, yNorm: number) {
    if (!recipients[activeRecipient]?.email) {
      setError('Add the recipient this field belongs to first.')
      return
    }
    setError(null)

    const size = DEFAULTS[activeType]
    setFields((current) => [
      ...current,
      {
        recipient_index: activeRecipient,
        type: activeType,
        page,
        // Clamped so a click near an edge cannot produce a box that hangs off
        // the page — the database CHECK constraint would reject it anyway.
        x: Math.min(Math.max(xNorm - size.w / 2, 0), 1 - size.w),
        y: Math.min(Math.max(yNorm - size.h / 2, 0), 1 - size.h),
        w: size.w,
        h: size.h,
        required: true,
      },
    ])
  }

  function removeField(index: number) {
    setFields((current) => current.filter((_, i) => i !== index))
  }

  function updateRecipient(index: number, patch: Partial<RecipientInput>) {
    setRecipients((current) =>
      current.map((r, i) => (i === index ? { ...r, ...patch } : r)),
    )
  }

  function addRecipient() {
    setRecipients((current) => [...current, { name: '', email: '' }])
  }

  function removeRecipient(index: number) {
    setRecipients((current) => current.filter((_, i) => i !== index))
    // Fields belonging to the removed recipient go too, and later indices shift
    // down to stay pointing at the same person.
    setFields((current) =>
      current
        .filter((f) => f.recipient_index !== index)
        .map((f) => ({
          ...f,
          recipient_index:
            f.recipient_index > index ? f.recipient_index - 1 : f.recipient_index,
        })),
    )
    setActiveRecipient(0)
  }

  async function createAndSend() {
    if (!document) return
    setSubmitting(true)
    setError(null)
    try {
      const { envelope } = await api.createEnvelope({
        document_id: document.id,
        subject,
        message: message || undefined,
        expires_in_days: expiresInDays,
        recipients,
        fields,
      })
      await api.sendEnvelope(envelope.uuid)
      navigate(`/envelopes/${envelope.uuid}`)
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Could not send the envelope.')
      setSubmitting(false)
    }
  }

  const ready =
    document !== null &&
    subject.trim().length > 0 &&
    fields.length > 0 &&
    recipients.every((r) => r.name.trim() && r.email.trim())

  return (
    <div>
      <h1 className="mb-1 text-xl font-semibold tracking-tight">New envelope</h1>
      <p className="mb-6 text-sm text-slate-500">
        Upload a PDF, add recipients, then click the page to place fields.
      </p>

      {error && (
        <p className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{error}</p>
      )}

      {!document && (
        <div className="rounded-lg border border-dashed border-slate-300 bg-white p-12 text-center">
          <label className="cursor-pointer">
            <span className="rounded-md bg-blue-700 px-4 py-2 text-sm font-medium text-white hover:bg-blue-800">
              {uploading ? 'Uploading…' : 'Choose a PDF'}
            </span>
            <input
              type="file"
              accept="application/pdf,.pdf"
              className="hidden"
              onChange={upload}
              disabled={uploading}
            />
          </label>
          <p className="mt-3 text-xs text-slate-500">Up to 20 MB. Not password-protected.</p>

          {/* So an evaluator does not have to go and find a PDF first. */}
          <p className="mt-5 border-t border-slate-200 pt-4 text-sm text-slate-600">
            Don't have one handy?{' '}
            <button
              type="button"
              onClick={useSample}
              disabled={uploading}
              className="font-medium text-blue-700 underline hover:text-blue-800 disabled:text-slate-400"
            >
              Use the sample agreement
            </button>
          </p>
        </div>
      )}

      {document && (
        <div className="grid gap-6 lg:grid-cols-[320px_1fr]">
          {/* ------------------------------------------------ left column */}
          <aside className="space-y-5">
            <section className="rounded-lg border border-slate-200 bg-white p-4">
              <h2 className="mb-3 text-sm font-semibold">Document</h2>
              <p className="truncate text-sm text-slate-700">{document.filename}</p>
              <p className="mt-1 text-xs text-slate-500">
                {document.page_count} page{document.page_count === 1 ? '' : 's'} ·{' '}
                {(document.size_bytes / 1024).toFixed(0)} KB
              </p>
              <p className="mt-2 break-all font-mono text-[10px] text-slate-400">
                SHA-256 {document.sha256_original.slice(0, 32)}…
              </p>
            </section>

            <section className="rounded-lg border border-slate-200 bg-white p-4">
              <div className="mb-3 flex items-center justify-between">
                <h2 className="text-sm font-semibold">Recipients</h2>
                <button
                  onClick={addRecipient}
                  className="text-xs font-medium text-blue-700 hover:underline"
                >
                  Add
                </button>
              </div>

              <div className="space-y-3">
                {recipients.map((recipient, index) => {
                  const [bg, fg] = (
                    RECIPIENT_COLOURS[index % RECIPIENT_COLOURS.length]
                  ).split('|')
                  const active = activeRecipient === index

                  return (
                    <div
                      key={index}
                      onClick={() => setActiveRecipient(index)}
                      className={`cursor-pointer rounded-md border p-3 transition ${
                        active ? 'border-blue-600 bg-blue-50/50' : 'border-slate-200'
                      }`}
                    >
                      <div className="mb-2 flex items-center gap-2">
                        <span
                          className="h-3 w-3 rounded-full"
                          style={{ background: fg, boxShadow: `0 0 0 3px ${bg}` }}
                        />
                        <span className="text-xs font-medium text-slate-600">
                          Signer {index + 1}
                        </span>
                        {recipients.length > 1 && (
                          <button
                            onClick={(e) => {
                              e.stopPropagation()
                              removeRecipient(index)
                            }}
                            className="ml-auto text-xs text-slate-400 hover:text-red-600"
                          >
                            Remove
                          </button>
                        )}
                      </div>
                      <input
                        value={recipient.name}
                        onChange={(e) => updateRecipient(index, { name: e.target.value })}
                        placeholder="Full name"
                        className="mb-2 w-full rounded border border-slate-300 px-2 py-1.5 text-sm outline-none focus:border-blue-600"
                      />
                      <input
                        type="email"
                        value={recipient.email}
                        onChange={(e) => updateRecipient(index, { email: e.target.value })}
                        placeholder="email@example.com"
                        className="w-full rounded border border-slate-300 px-2 py-1.5 text-sm outline-none focus:border-blue-600"
                      />
                    </div>
                  )
                })}
              </div>
            </section>

            <section className="rounded-lg border border-slate-200 bg-white p-4">
              <h2 className="mb-3 text-sm font-semibold">
                Field to place
                <span className="ml-1 font-normal text-slate-400">
                  ({fields.length} placed)
                </span>
              </h2>
              <div className="grid grid-cols-2 gap-2">
                {(Object.keys(DEFAULTS) as FieldType[]).map((type) => (
                  <button
                    key={type}
                    onClick={() => setActiveType(type)}
                    className={`rounded-md border px-2 py-1.5 text-xs font-medium capitalize transition ${
                      activeType === type
                        ? 'border-blue-600 bg-blue-50 text-blue-700'
                        : 'border-slate-200 text-slate-600 hover:border-slate-300'
                    }`}
                  >
                    {type}
                  </button>
                ))}
              </div>
              <p className="mt-3 text-xs text-slate-500">
                Click anywhere on the document to drop the selected field for the
                highlighted signer.
              </p>
            </section>

            <section className="rounded-lg border border-slate-200 bg-white p-4">
              <h2 className="mb-3 text-sm font-semibold">Message</h2>
              <input
                value={subject}
                onChange={(e) => setSubject(e.target.value)}
                placeholder="Subject"
                className="mb-2 w-full rounded border border-slate-300 px-2 py-1.5 text-sm outline-none focus:border-blue-600"
              />
              <textarea
                value={message}
                onChange={(e) => setMessage(e.target.value)}
                placeholder="Optional note to signers"
                rows={3}
                className="mb-2 w-full rounded border border-slate-300 px-2 py-1.5 text-sm outline-none focus:border-blue-600"
              />
              <label className="block text-xs text-slate-500">
                Link expires after
                <input
                  type="number"
                  min={1}
                  max={365}
                  value={expiresInDays}
                  onChange={(e) => setExpiresInDays(Number(e.target.value))}
                  className="mx-2 w-16 rounded border border-slate-300 px-2 py-1 text-sm text-slate-900 outline-none focus:border-blue-600"
                />
                days
              </label>
            </section>

            <button
              disabled={!ready || submitting}
              onClick={createAndSend}
              className="w-full rounded-md bg-blue-700 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-blue-800 disabled:cursor-not-allowed disabled:bg-slate-300"
            >
              {submitting ? 'Sending…' : 'Send for signature'}
            </button>
          </aside>

          {/* ----------------------------------------------- right column */}
          <div className="rounded-lg bg-slate-100 p-4">
            <PdfViewer
              data={pdfData}
              scale={1.3}
              onPageClick={(page, x, y) => placeField(page, x, y)}
              renderOverlay={(pageIndex, box: PageBox) => (
                <>
                  {fields.map((field, index) =>
                    field.page !== pageIndex ? null : (
                      <FieldBox
                        key={index}
                        field={field}
                        box={box}
                        label={
                          recipients[field.recipient_index]?.name ||
                          `Signer ${field.recipient_index + 1}`
                        }
                        colour={
                          RECIPIENT_COLOURS[
                            field.recipient_index % RECIPIENT_COLOURS.length
                          ]
                        }
                        onRemove={() => removeField(index)}
                      />
                    ),
                  )}
                </>
              )}
            />
          </div>
        </div>
      )}
    </div>
  )
}

function FieldBox({
  field,
  box,
  label,
  colour,
  onRemove,
}: {
  field: PlacedField
  box: PageBox
  label: string
  colour: string
  onRemove: () => void
}) {
  const [bg, fg] = colour.split('|')

  return (
    <div
      className="field-box pointer-events-auto group rounded-sm border-2"
      style={{
        left: field.x * box.width,
        top: field.y * box.height,
        width: field.w * box.width,
        height: field.h * box.height,
        background: bg,
        borderColor: fg,
      }}
    >
      <span
        className="absolute -top-4 left-0 whitespace-nowrap text-[10px] font-medium"
        style={{ color: fg }}
      >
        {field.type} · {label}
      </span>
      <button
        onClick={(e) => {
          e.stopPropagation()
          onRemove()
        }}
        className="absolute -right-2 -top-2 hidden h-4 w-4 place-items-center rounded-full bg-red-600 text-[10px] font-bold text-white group-hover:grid"
        title="Remove field"
      >
        ×
      </button>
    </div>
  )
}
