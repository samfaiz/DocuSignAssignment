import { useState } from 'react'

type Consent = 'granted' | 'denied' | 'unsupported' | 'failed'

type Props = {
  onDecision: (payload: {
    consent: Consent
    latitude?: number
    longitude?: number
    accuracy?: number
  }) => Promise<void>
}

/**
 * The location question, asked once inside a dialog.
 *
 * The signer is told what will be recorded before the browser's own permission
 * prompt appears — a prompt with no context is how people end up agreeing to
 * things they did not understand. Refusing is a first-class answer: it is
 * recorded, it never blocks signing, and its button sits beside the other one
 * rather than hidden as a link.
 */
export default function LocationConsent({ onDecision }: Props) {
  const [busy, setBusy] = useState(false)
  // Shown inside the dialog: it covers the viewport, so anything the page
  // underneath renders is invisible, which looks like the button doing nothing.
  const [error, setError] = useState<string | null>(null)

  async function record(payload: Parameters<Props['onDecision']>[0]) {
    setBusy(true)
    setError(null)
    try {
      await onDecision(payload)
    } catch {
      setError('That could not be saved. Please try again.')
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
      (position) =>
        void record({
          consent: 'granted',
          latitude: position.coords.latitude,
          longitude: position.coords.longitude,
          accuracy: position.coords.accuracy,
        }),
      (error) =>
        // Refusing the browser prompt is a decision; a device that cannot get a
        // fix is a different fact. Recording them identically would misrepresent
        // someone who agreed but was indoors with no signal.
        void record({
          consent: error.code === error.PERMISSION_DENIED ? 'denied' : 'failed',
        }),
      { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 },
    )
  }

  return (
    <div>
      <p className="text-[13px] leading-relaxed text-slate-700">
        This adds approximate coordinates to the signing record, alongside the
        time and IP address already captured. Your browser will ask permission
        first, and the location appears on the certificate attached to the signed
        document.
      </p>
      <p className="mt-2 text-[12px] leading-relaxed text-slate-500">
        Declining changes nothing about your signature or its validity. Either
        way, your answer is recorded.
      </p>

      {error && (
        <p className="mt-3 rounded-md bg-red-50 px-3 py-2 text-xs text-red-700">{error}</p>
      )}

      <div className="mt-4 flex gap-2">
        <button
          type="button"
          onClick={share}
          disabled={busy}
          className="flex-1 rounded-md bg-blue-700 px-3 py-2 text-sm font-medium text-white transition hover:bg-blue-800 disabled:bg-slate-300"
        >
          {busy ? 'Waiting…' : 'Share my location'}
        </button>
        <button
          type="button"
          onClick={() => void record({ consent: 'denied' })}
          disabled={busy}
          className="flex-1 rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 disabled:opacity-50"
        >
          No thanks
        </button>
      </div>
    </div>
  )
}
