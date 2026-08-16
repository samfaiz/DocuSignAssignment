import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
} from 'react'

/**
 * The guided tour.
 *
 * Content and state only — the panel that renders it lives in components/.
 * The tour observes; it never drives. Nothing here can change what the
 * application does, so a reviewer is always testing the real flow.
 */

export type TourStep = {
  stage: 'Admin' | 'Signer' | 'Verify' | 'Start'
  title: string
  what: string
  decision: string
  instead: string
  tryThis?: string
  file?: string
}

export const TOUR: Record<string, TourStep> = {
  'demo.start': {
    stage: 'Start',
    title: 'Two ways in',
    what: 'Start a signing ceremony as the recipient, or sign in as the administrator and build an envelope from scratch.',
    decision:
      'The signer portal is the harder half — no account, no session, authenticated purely by a tokenised link plus a passcode. That is where the interesting decisions are.',
    instead:
      'A single admin-only demo would show the easy half: an authenticated user clicking around their own data.',
    tryThis: 'Start with the signing ceremony, then sign in as admin to see the evidence it produced.',
  },

  'admin.login': {
    stage: 'Admin',
    title: 'Sign-in',
    what: 'Bearer-token auth via Laravel Sanctum, throttled per email and IP pair.',
    decision:
      'The response is identical for an unknown account and a wrong password, so it cannot be used to enumerate who has an account here.',
    instead:
      '"No user with that email" is friendlier and hands an attacker a free list of valid addresses.',
    file: 'api/app/Http/Controllers/Api/AuthController.php',
  },

  'admin.envelopes': {
    stage: 'Admin',
    title: 'Envelopes',
    what: 'Every document sent for signature, with per-recipient status.',
    decision:
      'An envelope is its own entity rather than a flag on the document, because one document can be sent many times to different people with different fields.',
    instead:
      'Signing state stored on the document row — which collapses the moment the same contract goes to a second counterparty.',
  },

  'admin.new': {
    stage: 'Admin',
    title: 'Placing fields',
    what: 'Upload a PDF, add recipients, then click the page to drop a field for the highlighted signer.',
    decision:
      'Coordinates are stored as fractions of the page, 0 to 1, with a top-left origin. The server converts them into PDF user space at stamping time, so the client never decides where ink actually lands.',
    instead:
      'Storing pixel offsets. Those are correct only at the exact zoom and screen DPI they were captured at, and they let a modified client place a signature anywhere.',
    tryThis:
      'Drop a signature field, then zoom your browser and reload — the field stays where you put it.',
    file: 'web/src/pages/NewEnvelope.tsx',
  },

  'admin.detail': {
    stage: 'Admin',
    title: 'The evidence, live',
    what: 'Seal details, the recipient list with how each person was verified, and the full hash-chained audit trail.',
    decision:
      'The chain is recomputed from scratch on every load. A cached "valid" is exactly the thing an attacker would want to poison, so there is nothing to poison.',
    instead:
      'A stored is_valid column, updated when events are written — trusted forever after, by whoever wrote it.',
    tryThis:
      'Watch the trail fill in while a signer works: opened, passcode verified, consented, signed, sealed.',
    file: 'api/app/Services/AuditLogger.php',
  },

  'admin.verify': {
    stage: 'Verify',
    title: 'Integrity is not trust',
    what: 'Upload any PDF. It reports separately whether the bytes are unaltered and whether the signing certificate is trusted.',
    decision:
      'Those two fail for completely different reasons — a tampered file versus an untrusted issuer — and collapsing them into one verdict hides which one went wrong.',
    instead:
      'A single green tick. Useless the moment something is actually wrong.',
    tryThis:
      'Upload the signed PDF and check it passes. Then open it in a hex editor, change one byte in the middle, and upload it again.',
    file: 'api/app/Http/Controllers/Api/VerificationController.php',
  },

  'signer.verify': {
    stage: 'Signer',
    title: 'Two factors before the document',
    what: 'The emailed link proves someone can read that inbox. The passcode proves they are reading it right now.',
    decision:
      'The document itself is not served until the passcode passes — the API refuses it, not just the interface. Codes are bcrypt-hashed, expire in ten minutes and lock out after five failures.',
    instead:
      'Link-only access, which is what most "click here to sign" emails give you. Mail gets forwarded, archived and breached; holding a URL is not identity.',
    tryThis:
      'Enter a wrong code and watch it fail, then check the audit trail afterwards — failures are recorded too.',
    file: 'api/app/Services/SignerTokenService.php',
  },

  'signer.consent': {
    stage: 'Signer',
    title: 'Consent, and to exactly what',
    what: 'Records the disclosure version and a SHA-256 of the precise text shown on screen, with the time and IP.',
    decision:
      'Storing a boolean answers "did they agree". In a dispute the question is "agree to what wording", and only the hash can answer that.',
    instead:
      'A tick-box column. Reword the disclosure a year later and every past consent silently points at text nobody ever saw.',
    file: 'api/app/Support/Disclosure.php',
  },

  'signer.sign': {
    stage: 'Signer',
    title: 'Three ways to sign',
    what: 'Draw it, type it in a script font, or upload an image of a real signature.',
    decision:
      'Typed names are rendered to PNG on the server, so the artefact sealed into the document does not depend on which fonts the signer happens to have. Uploads are fully decoded and re-encoded, which strips EXIF and anything else riding along with the pixels.',
    instead:
      'Trusting the browser to produce the final image. Then the bytes that get cryptographically sealed are bytes a client sent you.',
    tryThis:
      'Type your name and switch between the fonts — the preview uses the very same font files the server renders with.',
    file: 'sign-service/app/stamper.py',
  },

  'signer.done': {
    stage: 'Signer',
    title: 'What just happened',
    what: 'Your click was logged as its own intent event, the link was burned, and the sealing job was queued.',
    decision:
      'Intent is recorded separately from having filled the fields in, because the ESIGN Act and UETA turn on the signature being executed with intent to sign — not on the boxes being complete.',
    instead:
      'Treating "all fields filled" as the signature. That records completion, not intention.',
    tryThis:
      'Sign in as the administrator and open the envelope to see the seal and the audit trail this produced.',
    file: 'api/app/Http/Controllers/Api/SignerController.php',
  },

  'signer.sealing': {
    stage: 'Signer',
    title: 'Sealing, on a queue',
    what: 'The marks are composited, a Certificate of Completion is appended, and the whole file is sealed with a PAdES-B-LTA signature.',
    decision:
      'Queued, because sealing makes a round trip to a timestamp authority and fetches revocation data. It retries with widening backoff rather than turning a transient outage into a failed signature.',
    instead:
      'Sealing inline during the request. The signer would wait seconds, and a timestamp authority having a bad minute would look like their signature failing.',
    file: 'api/app/Jobs/SealEnvelope.php',
  },
}

type TourState = {
  enabled: boolean
  toggle: () => void
  step: TourStep | null
  setStepKey: (key: string | null) => void
}

const TourContext = createContext<TourState | null>(null)

const STORAGE_KEY = 'signdesk.tour'

export function TourProvider({ children }: { children: React.ReactNode }) {
  // Off by default: a reviewer should be able to judge the product on its own
  // before being told what to think about it.
  const [enabled, setEnabled] = useState(
    () => localStorage.getItem(STORAGE_KEY) === 'on',
  )
  const [stepKey, setStepKey] = useState<string | null>(null)

  // The persist happens here rather than inside the state updater: updaters must
  // be pure, and StrictMode deliberately invokes them twice to surface exactly
  // this kind of hidden side effect.
  const toggle = useCallback(() => {
    setEnabled((on) => !on)
  }, [])

  useEffect(() => {
    localStorage.setItem(STORAGE_KEY, enabled ? 'on' : 'off')
  }, [enabled])

  const value = useMemo<TourState>(
    () => ({
      enabled,
      toggle,
      step: stepKey ? (TOUR[stepKey] ?? null) : null,
      setStepKey,
    }),
    [enabled, toggle, stepKey],
  )

  return <TourContext.Provider value={value}>{children}</TourContext.Provider>
}

export function useTour(): TourState {
  const context = useContext(TourContext)
  if (!context) throw new Error('useTour must be used inside a TourProvider')
  return context
}

/** Declares which step the current screen is on. */
export function useTourStep(key: string): void {
  const { setStepKey } = useTour()

  useEffect(() => {
    setStepKey(key)
    return () => setStepKey(null)
  }, [key, setStepKey])
}
