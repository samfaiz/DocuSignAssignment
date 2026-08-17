import { useCallback, useEffect, useRef, useState } from 'react'
import { useParams, useSearchParams } from 'react-router-dom'
import LocationConsent from '../components/LocationConsent'
import PdfViewer, { type PageBox } from '../components/PdfViewer'
import SignaturePad, { type AdoptedSignature } from '../components/SignaturePad'
import {
  ApiError,
  demoApi,
  signerApi,
  type FieldValueInput,
  type SignerField,
  type SignerPayload,
} from '../lib/api'
import { useTourStep } from '../lib/tour'

type Step = 'loading' | 'error' | 'verify' | 'consent' | 'sign' | 'done'

export default function Sign() {
  const { uuid = '' } = useParams()
  const [params] = useSearchParams()
  const token = params.get('t') ?? ''

  const [step, setStep] = useState<Step>('loading')
  const [payload, setPayload] = useState<SignerPayload | null>(null)
  const [pdfData, setPdfData] = useState<ArrayBuffer | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const [code, setCode] = useState('')
  // Demo mode only. Null means either the passcode has not been issued yet or
  // the API has demo mode off, in which case nothing is shown at all.
  const [demoCode, setDemoCode] = useState<string | null>(null)
  const [assetId, setAssetId] = useState<number | null>(null)
  const [assetPreview, setAssetPreview] = useState<string | null>(null)
  const [filled, setFilled] = useState<Record<number, string>>({})

  const load = useCallback(async () => {
    try {
      const data = await signerApi.load(uuid, token)
      setPayload(data)

      if (data.recipient.signed_at) setStep('done')
      else if (!data.recipient.otp_verified) setStep('verify')
      else if (!data.recipient.has_consented) setStep('consent')
      else setStep('sign')

      return data
    } catch (e) {
      setError(
        e instanceof ApiError
          ? e.status === 410
            ? 'This signing link has expired.'
            : e.status === 404
              ? 'This signing link is not valid. It may already have been used.'
              : e.message
          : 'Could not load this document.',
      )
      setStep('error')
      return null
    }
  }, [uuid, token])

  useEffect(() => {
    void load()
  }, [load])

  // The document is only fetched once the passcode is verified — the API
  // refuses it before that, and there is no reason for the SPA to ask earlier.
  //
  // Guarded by a ref rather than by `pdfData`, for two reasons: state updates
  // are asynchronous, so several renders can pass the `!pdfData` check before
  // the first response lands; and every fetch writes a `viewed_document` entry
  // to the audit trail, so a re-render loop here does not just waste bandwidth,
  // it floods the evidence log with hundreds of identical events.
  const documentRequested = useRef(false)

  useEffect(() => {
    if (documentRequested.current) return
    if (!payload?.recipient.otp_verified) return

    documentRequested.current = true

    signerApi
      .documentBlob(uuid, token)
      .then((blob) => blob.arrayBuffer())
      .then(setPdfData)
      .catch(() => {
        documentRequested.current = false
        setError('Could not load the document.')
      })
  }, [payload, uuid, token])

  async function guard(action: () => Promise<void>) {
    setBusy(true)
    setError(null)
    try {
      await action()
    } catch (e) {
      // A token that has gone away mid-ceremony — expired, voided, already
      // used, or belonging to an envelope that no longer exists — is terminal.
      // Showing it as a banner above the form leaves the signer poking at
      // controls that can never succeed, so take over the whole screen instead.
      if (e instanceof ApiError && (e.status === 404 || e.status === 410)) {
        setError(
          e.status === 410
            ? 'This signing link has expired.'
            : 'This signing link is no longer valid. It may already have been used, or the envelope may have been withdrawn.',
        )
        setStep('error')
        return
      }

      setError(e instanceof ApiError ? e.message : 'Something went wrong.')
    } finally {
      setBusy(false)
    }
  }

  const requestOtp = () =>
    guard(async () => {
      const { expires_in_minutes } = await signerApi.requestOtp(uuid, token)
      setNotice(`We sent a code to your email. It expires in ${expires_in_minutes} minutes.`)

      // Saves a reviewer a trip to the mail catcher. Absent outside demo mode,
      // where this endpoint does not exist at all — so failure is expected and
      // silent rather than an error.
      try {
        const { code: revealed } = await demoApi.otp(uuid, token)
        setDemoCode(revealed)
      } catch {
        setDemoCode(null)
      }
    })

  const verifyOtp = () =>
    guard(async () => {
      await signerApi.verifyOtp(uuid, token, code)
      setNotice(null)
      await load()
    })

  const acceptConsent = () =>
    guard(async () => {
      await signerApi.consent(uuid, token)
      await load()
    })

  const adopt = (signature: AdoptedSignature) =>
    guard(async () => {
      const { asset, preview } = await signerApi.createSignature(uuid, token, signature)
      setAssetId(asset.id)
      // Always the server's rendering, never the local one. For a typed name the
      // browser has no image at all, and for an upload the server's copy is the
      // background-stripped version that actually gets sealed — so previewing
      // anything else would show the signer something the document will not contain.
      setAssetPreview(preview)
    })

  const finish = () =>
    guard(async () => {
      if (!payload) return

      const values: FieldValueInput[] = payload.fields.map((field) => {
        if (field.type === 'signature' || field.type === 'initial') {
          return { field_id: field.id, asset_id: assetId ?? undefined }
        }
        return { field_id: field.id, text: filled[field.id] ?? '' }
      })

      await signerApi.saveFields(uuid, token, values)
      await signerApi.finish(uuid, token)
      setStep('done')
    })

  useTourStep(
    step === 'verify'
      ? 'signer.verify'
      : step === 'consent'
        ? 'signer.consent'
        : step === 'sign'
          ? 'signer.sign'
          : step === 'done'
            ? 'signer.done'
            : '',
  )

  /* ------------------------------------------------------------- render */

  if (step === 'loading') {
    return <Centered>Loading…</Centered>
  }

  if (step === 'error') {
    return (
      <Centered>
        <div className="max-w-md text-center">
          <h1 className="mb-2 text-lg font-semibold">This link cannot be opened</h1>
          <p className="text-sm text-slate-600">{error}</p>
        </div>
      </Centered>
    )
  }

  if (!payload) return null

  if (step === 'done') {
    return (
      <Centered>
        <div className="max-w-md text-center">
          <div className="mx-auto mb-4 grid h-12 w-12 place-items-center rounded-full bg-emerald-50 text-2xl text-emerald-600">
            ✓
          </div>
          <h1 className="mb-2 text-lg font-semibold">Signed</h1>
          <p className="text-sm text-slate-600">
            Thank you. Once everyone has signed, a copy of{' '}
            <strong>{payload.envelope.subject}</strong> will be emailed to you.
          </p>
          <p className="mt-4 text-xs text-slate-500">
            The signed file carries a cryptographic signature and a certificate of
            completion recording how you were identified and when you signed.
          </p>
        </div>
      </Centered>
    )
  }

  const signatureFields = payload.fields.filter(
    (f) => f.type === 'signature' || f.type === 'initial',
  )
  const textFields = payload.fields.filter(
    (f) => f.type !== 'signature' && f.type !== 'initial',
  )
  const textReady = textFields.every((f) => !f.required || (filled[f.id] ?? '').trim())
  const readyToFinish =
    (signatureFields.length === 0 || assetId !== null) && textReady && payload.my_turn

  return (
    <div className="min-h-full">
      <header className="border-b border-slate-200 bg-white">
        <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-2 px-6 py-3">
          <div>
            <h1 className="text-sm font-semibold">{payload.envelope.subject}</h1>
            <p className="text-xs text-slate-500">
              Sent by {payload.envelope.sender} · {payload.envelope.document.filename}
            </p>
          </div>
          <span className="text-xs text-slate-500">
            Signing as {payload.recipient.name}
          </span>
        </div>
      </header>

      <main className="mx-auto max-w-6xl px-6 py-8">
        {error && (
          <p className="mb-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-700">{error}</p>
        )}
        {notice && (
          <p className="mb-4 rounded-md bg-blue-50 px-4 py-3 text-sm text-blue-800">{notice}</p>
        )}

        {/* -------------------------------------------------------- step 1 */}
        {step === 'verify' && (
          <div className="mx-auto max-w-md rounded-lg border border-slate-200 bg-white p-6">
            <h2 className="mb-1 text-base font-semibold">Verify it is you</h2>
            <p className="mb-5 text-sm text-slate-600">
              We will email a one-time code to{' '}
              <strong>{payload.recipient.email}</strong>. Anyone can forward a link —
              this step confirms you are the intended signer.
            </p>

            <button
              onClick={requestOtp}
              disabled={busy}
              className="mb-4 w-full rounded-md border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50 disabled:opacity-50"
            >
              {busy ? 'Sending…' : 'Email me a code'}
            </button>

            {demoCode && (
              <div className="mb-4 rounded-md border border-amber-200 bg-amber-50 p-3">
                <p className="text-[11px] font-medium uppercase tracking-wide text-amber-800">
                  Demo mode
                </p>
                <p className="mt-1 text-[13px] leading-relaxed text-amber-900">
                  Your code is{' '}
                  <span className="font-mono font-medium">{demoCode}</span>. In
                  production this only ever reaches the signer's inbox — it is
                  shown here so you do not have to open the mail catcher.
                </p>
                <button
                  onClick={() => setCode(demoCode)}
                  className="mt-2 text-xs font-medium text-amber-900 underline"
                >
                  Fill it in
                </button>
              </div>
            )}

            <label className="mb-1 block text-sm font-medium text-slate-700">
              Enter the 6-digit code
            </label>
            <input
              value={code}
              onChange={(e) => setCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
              inputMode="numeric"
              placeholder="000000"
              className="mb-3 w-full rounded-md border border-slate-300 px-3 py-2 text-center font-mono text-lg tracking-[0.4em] outline-none focus:border-blue-600 focus:ring-2 focus:ring-blue-100"
            />
            <button
              onClick={verifyOtp}
              disabled={busy || code.length !== 6}
              className="w-full rounded-md bg-blue-700 px-4 py-2 text-sm font-medium text-white hover:bg-blue-800 disabled:bg-slate-300"
            >
              Verify
            </button>
          </div>
        )}

        {/* -------------------------------------------------------- step 2 */}
        {step === 'consent' && (
          <div className="mx-auto max-w-2xl rounded-lg border border-slate-200 bg-white p-6">
            <h2 className="mb-1 text-base font-semibold">
              Consent to use electronic records
            </h2>
            <p className="mb-4 text-xs text-slate-500">
              Version {payload.disclosure.version}
            </p>

            <pre className="mb-5 max-h-80 overflow-y-auto whitespace-pre-wrap rounded-md bg-slate-50 p-4 font-sans text-xs leading-relaxed text-slate-700">
              {payload.disclosure.text}
            </pre>

            <button
              onClick={acceptConsent}
              disabled={busy}
              className="w-full rounded-md bg-blue-700 px-4 py-2.5 text-sm font-medium text-white hover:bg-blue-800 disabled:bg-slate-300"
            >
              I agree and wish to sign electronically
            </button>
          </div>
        )}

        {/* -------------------------------------------------------- step 3 */}
        {step === 'sign' && (
          <div className="grid gap-6 lg:grid-cols-[340px_1fr]">
            <aside className="space-y-5">
              {!payload.my_turn && (
                <p className="rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-800">
                  Another signer must complete their part before you can sign.
                </p>
              )}

              <section>
                <h2 className="mb-2 text-sm font-semibold">Your signature</h2>
                {assetId ? (
                  <div className="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-center">
                    {assetPreview ? (
                      <img
                        src={assetPreview}
                        alt="Your signature"
                        className="mx-auto max-h-20"
                      />
                    ) : (
                      <p className="text-sm text-emerald-800">Signature ready</p>
                    )}
                    <button
                      onClick={() => {
                        setAssetId(null)
                        setAssetPreview(null)
                      }}
                      className="mt-2 text-xs text-emerald-700 underline"
                    >
                      Change
                    </button>
                  </div>
                ) : (
                  <SignaturePad
                    signerName={payload.recipient.name}
                    fonts={payload.fonts}
                    onAdopt={adopt}
                    busy={busy}
                  />
                )}
              </section>

              <LocationConsent
                current={payload.recipient.location_consent ?? 'not_asked'}
                onDecision={async (decision) => {
                  await signerApi.shareLocation(uuid, token, decision)
                  await load()
                }}
              />

              {textFields.length > 0 && (
                <section className="rounded-lg border border-slate-200 bg-white p-4">
                  <h2 className="mb-3 text-sm font-semibold">Other fields</h2>
                  <div className="space-y-3">
                    {textFields.map((field) => (
                      <div key={field.id}>
                        <label className="mb-1 block text-xs font-medium capitalize text-slate-600">
                          {field.type} (page {field.page + 1})
                        </label>
                        <input
                          value={filled[field.id] ?? ''}
                          onChange={(e) =>
                            setFilled((current) => ({
                              ...current,
                              [field.id]: e.target.value,
                            }))
                          }
                          placeholder={
                            field.type === 'date'
                              ? new Date().toLocaleDateString('en-GB', {
                                  day: 'numeric',
                                  month: 'long',
                                  year: 'numeric',
                                })
                              : ''
                          }
                          className="w-full rounded border border-slate-300 px-2 py-1.5 text-sm outline-none focus:border-blue-600"
                        />
                      </div>
                    ))}
                  </div>
                </section>
              )}

              <button
                onClick={finish}
                disabled={!readyToFinish || busy}
                className="w-full rounded-md bg-blue-700 px-4 py-3 text-sm font-medium text-white transition hover:bg-blue-800 disabled:cursor-not-allowed disabled:bg-slate-300"
              >
                {busy ? 'Signing…' : 'I agree — sign this document'}
              </button>
              <p className="text-center text-xs text-slate-500">
                Clicking above applies your signature and records the time, your IP
                address and how you were verified.
              </p>
            </aside>

            <div className="rounded-lg bg-slate-100 p-4">
              <PdfViewer
                data={pdfData}
                scale={1.3}
                renderOverlay={(pageIndex, box: PageBox) => (
                  <>
                    {payload.fields
                      .filter((f) => f.page === pageIndex)
                      .map((field) => (
                        <SignerFieldBox
                          key={field.id}
                          field={field}
                          box={box}
                          filledText={filled[field.id]}
                          signed={
                            (field.type === 'signature' || field.type === 'initial') &&
                            assetId !== null
                          }
                          preview={assetPreview}
                        />
                      ))}
                  </>
                )}
              />
            </div>
          </div>
        )}
      </main>
    </div>
  )
}

function SignerFieldBox({
  field,
  box,
  filledText,
  signed,
  preview,
}: {
  field: SignerField
  box: PageBox
  filledText?: string
  signed: boolean
  preview: string | null
}) {
  const complete = signed || Boolean(filledText?.trim())

  return (
    <div
      className={`field-box grid place-items-center overflow-hidden rounded-sm border-2 ${
        complete
          ? 'border-emerald-500 bg-emerald-50/60'
          : 'animate-pulse border-blue-600 bg-blue-50/60'
      }`}
      style={{
        left: field.x * box.width,
        top: field.y * box.height,
        width: field.w * box.width,
        height: field.h * box.height,
      }}
    >
      {signed && preview ? (
        <img src={preview} alt="" className="max-h-full max-w-full object-contain" />
      ) : filledText ? (
        <span className="truncate px-1 text-[11px] text-slate-800">{filledText}</span>
      ) : (
        <span className="text-[10px] font-medium capitalize text-blue-700">
          {complete ? '✓' : field.type}
        </span>
      )}
    </div>
  )
}

function Centered({ children }: { children: React.ReactNode }) {
  return <div className="grid min-h-full place-items-center px-6 py-16">{children}</div>
}
