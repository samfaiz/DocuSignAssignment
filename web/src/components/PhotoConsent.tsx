import { useEffect, useRef, useState } from 'react'

type Consent = 'granted' | 'denied' | 'unsupported' | 'failed'

type Props = {
  current: string
  onDecision: (payload: { consent: Consent; image?: string }) => Promise<void>
}

/**
 * Optional photograph at signing, when the sender has asked for one.
 *
 * A face image is biometric data, so this component is deliberately careful:
 * the camera is not touched until the signer presses a button, the live preview
 * is stopped the instant a picture is taken or the step ends, and declining is
 * as easy as agreeing. The wording avoids "verify" throughout — a photograph
 * without a document check and a liveness test is evidence of presence, not
 * proof of identity, and telling the signer otherwise would be a lie.
 */
export default function PhotoConsent({ current, onDecision }: Props) {
  const videoRef = useRef<HTMLVideoElement>(null)
  const streamRef = useRef<MediaStream | null>(null)

  const [busy, setBusy] = useState(false)
  const [live, setLive] = useState(false)
  const [decided, setDecided] = useState(current !== 'not_asked')
  const [outcome, setOutcome] = useState<Consent | null>(
    current !== 'not_asked' ? (current as Consent) : null,
  )

  /** The camera must never outlive this component, however it unmounts. */
  function stopCamera() {
    streamRef.current?.getTracks().forEach((track) => track.stop())
    streamRef.current = null
    setLive(false)
  }

  useEffect(() => stopCamera, [])

  async function record(payload: { consent: Consent; image?: string }) {
    setBusy(true)
    try {
      await onDecision(payload)
      setOutcome(payload.consent)
      setDecided(true)
    } finally {
      stopCamera()
      setBusy(false)
    }
  }

  async function startCamera() {
    if (!navigator.mediaDevices?.getUserMedia) {
      void record({ consent: 'unsupported' })
      return
    }

    setBusy(true)
    try {
      const stream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: 'user', width: { ideal: 720 } },
        audio: false,
      })
      streamRef.current = stream
      setLive(true)
      if (videoRef.current) {
        videoRef.current.srcObject = stream
        await videoRef.current.play()
      }
    } catch (error) {
      // Refusing the browser prompt is a decision; a camera that is missing or
      // already in use is a different fact, and the record should not conflate
      // someone who said no with someone whose webcam was busy.
      const denied =
        error instanceof DOMException &&
        (error.name === 'NotAllowedError' || error.name === 'SecurityError')
      void record({ consent: denied ? 'denied' : 'failed' })
    } finally {
      setBusy(false)
    }
  }

  function capture() {
    const video = videoRef.current
    if (!video) return

    const canvas = document.createElement('canvas')
    canvas.width = video.videoWidth
    canvas.height = video.videoHeight
    canvas.getContext('2d')?.drawImage(video, 0, 0)

    // The server re-encodes this anyway, stripping any metadata; sending JPEG
    // just keeps the request small.
    void record({ consent: 'granted', image: canvas.toDataURL('image/jpeg', 0.85) })
  }

  if (decided) {
    return (
      <div className="rounded-lg border border-slate-200 bg-white p-4">
        <p className="text-sm font-medium text-slate-700">Photograph</p>
        <p className="mt-1 text-[13px] leading-relaxed text-slate-600">
          {outcome === 'granted' &&
            'Taken and stored with the signing record. It is kept as evidence of presence, not as identity verification.'}
          {outcome === 'denied' &&
            'Not taken. Your decision was recorded; signing continues normally.'}
          {outcome === 'unsupported' &&
            'Your browser does not offer camera access. Recorded as unavailable.'}
          {outcome === 'failed' &&
            'The camera could not be started. Recorded as attempted.'}
        </p>
      </div>
    )
  }

  return (
    <div className="rounded-lg border border-slate-200 bg-white p-4">
      <p className="text-sm font-medium text-slate-700">
        Photograph at signing?{' '}
        <span className="font-normal text-slate-400">Optional</span>
      </p>
      <p className="mt-1 text-[13px] leading-relaxed text-slate-600">
        The sender has asked for a photo to accompany this signature. It is taken
        by your own device, stored with the signing record, and appears on the
        certificate attached to the signed document.
      </p>
      <p className="mt-2 text-[12px] leading-relaxed text-slate-500">
        This is not an identity check — no document is examined and no facial
        comparison is made. Declining changes nothing about your signature or its
        validity.
      </p>

      {live && (
        <div className="mt-3 overflow-hidden rounded-md bg-slate-900">
          <video
            ref={videoRef}
            playsInline
            muted
            className="mx-auto block max-h-56 w-auto"
          />
        </div>
      )}

      <div className="mt-3 flex gap-2">
        {live ? (
          <button
            type="button"
            onClick={capture}
            disabled={busy}
            className="rounded-md bg-blue-700 px-3 py-1.5 text-xs font-medium text-white transition hover:bg-blue-800 disabled:opacity-50"
          >
            Take photo
          </button>
        ) : (
          <button
            type="button"
            onClick={startCamera}
            disabled={busy}
            className="rounded-md border border-slate-300 px-3 py-1.5 text-xs font-medium transition hover:bg-slate-50 disabled:opacity-50"
          >
            {busy ? 'Starting…' : 'Use camera'}
          </button>
        )}
        <button
          type="button"
          onClick={() => void record({ consent: 'denied' })}
          disabled={busy}
          className="rounded-md px-3 py-1.5 text-xs font-medium text-slate-500 transition hover:bg-slate-100 disabled:opacity-50"
        >
          No thanks
        </button>
      </div>
    </div>
  )
}
