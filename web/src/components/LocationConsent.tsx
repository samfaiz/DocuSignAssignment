import { useState } from 'react'

type Consent = 'granted' | 'denied' | 'unsupported' | 'failed'

type Props = {
  /** Whatever the server already has, so a reload does not re-ask. */
  current: string
  onDecision: (payload: {
    consent: Consent
    latitude?: number
    longitude?: number
    accuracy?: number
  }) => Promise<void>
}

/**
 * Optional location sharing.
 *
 * Two rules shape this component. The signer is told what will be recorded
 * *before* the browser permission prompt appears — a prompt with no context is
 * how people end up consenting to things they did not understand. And declining
 * is a first-class outcome: it is recorded, it never blocks signing, and the
 * button to decline is exactly as prominent as the button to share.
 */
export default function LocationConsent({ current, onDecision }: Props) {
  const [busy, setBusy] = useState(false)
  const [decided, setDecided] = useState(current !== 'not_asked')
  const [outcome, setOutcome] = useState<Consent | null>(
    current !== 'not_asked' ? (current as Consent) : null,
  )

  async function record(payload: Parameters<Props['onDecision']>[0]) {
    setBusy(true)
    try {
      await onDecision(payload)
      setOutcome(payload.consent)
      setDecided(true)
    } finally {
      setBusy(false)
    }
  }

  function share() {
    if (!('geolocation' in navigator)) {
      void record({ consent: 'unsupported' })
      return
    }

    setBusy(true)
    navigator.geolocation.getCurrentPosition(
      (position) => {
        void record({
          consent: 'granted',
          latitude: position.coords.latitude,
          longitude: position.coords.longitude,
          accuracy: position.coords.accuracy,
        })
      },
      (error) => {
        // PERMISSION_DENIED is a decision; anything else is the device failing
        // to work it out. Recording them as the same thing would misrepresent
        // a signer who agreed but was indoors with no signal.
        void record({
          consent: error.code === error.PERMISSION_DENIED ? 'denied' : 'failed',
        })
      },
      { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 },
    )
  }

  if (decided) {
    return (
      <div className="rounded-lg border border-slate-200 bg-white p-4">
        <p className="text-sm font-medium text-slate-700">Location</p>
        <p className="mt-1 text-[13px] leading-relaxed text-slate-600">
          {outcome === 'granted' && 'Shared and recorded with your signature.'}
          {outcome === 'denied' &&
            'Not shared. Your decision was recorded; signing continues normally.'}
          {outcome === 'unsupported' &&
            'Your browser does not offer location sharing. Recorded as unavailable.'}
          {outcome === 'failed' &&
            'Your device could not determine a location. Recorded as attempted.'}
        </p>
      </div>
    )
  }

  return (
    <div className="rounded-lg border border-slate-200 bg-white p-4">
      <p className="text-sm font-medium text-slate-700">
        Share your location?{' '}
        <span className="font-normal text-slate-400">Optional</span>
      </p>
      <p className="mt-1 text-[13px] leading-relaxed text-slate-600">
        Adds approximate coordinates to the signing record, alongside the time and
        IP address already captured. Your browser will ask permission first, and
        the coordinates appear on the certificate attached to the signed document.
      </p>
      <p className="mt-2 text-[12px] leading-relaxed text-slate-500">
        Declining changes nothing about your signature or its validity — the
        decision is simply recorded either way.
      </p>

      <div className="mt-3 flex gap-2">
        <button
          type="button"
          onClick={share}
          disabled={busy}
          className="rounded-md border border-slate-300 px-3 py-1.5 text-xs font-medium transition hover:bg-slate-50 disabled:opacity-50"
        >
          {busy ? 'Waiting…' : 'Share location'}
        </button>
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
