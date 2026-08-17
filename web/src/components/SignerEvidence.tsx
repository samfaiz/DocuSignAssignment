import { useEffect, useState } from 'react'
import { api, type EnvelopeDetail } from '../lib/api'

type Recipient = EnvelopeDetail['recipients'][number]

/**
 * What was captured about one signer, beyond the fact that they signed.
 *
 * The same material appears on the certificate of completion inside the sealed
 * PDF. It is repeated here because an administrator reviewing an envelope
 * should not have to download and open a document to see who signed it and
 * what was recorded — but it is the sealed copy that carries evidential weight,
 * since only that one is tamper-evident.
 */
export default function SignerEvidence({
  envelopeUuid,
  recipient,
}: {
  envelopeUuid: string
  recipient: Recipient
}) {
  const [photo, setPhoto] = useState<string | null>(null)

  useEffect(() => {
    if (!recipient.has_photo) return

    let url: string | null = null
    let cancelled = false

    api
      .recipientPhotoBlob(envelopeUuid, recipient.id)
      .then((blob) => {
        if (cancelled) return
        url = URL.createObjectURL(blob)
        setPhoto(url)
      })
      .catch(() => setPhoto(null))

    // Object URLs are held by the document until revoked; without this each
    // poll of the envelope page would leak another copy of the image.
    return () => {
      cancelled = true
      if (url) URL.revokeObjectURL(url)
    }
  }, [envelopeUuid, recipient.id, recipient.has_photo])

  const hasAnything =
    recipient.location_consent !== 'not_asked' ||
    recipient.photo_consent !== 'not_asked'

  if (!hasAnything) return null

  return (
    <div className="mt-3 flex flex-wrap items-start gap-4 rounded-md bg-slate-50 p-3">
      {photo && (
        <img
          src={photo}
          alt={`Photograph captured when ${recipient.name} signed`}
          className="h-24 w-24 shrink-0 rounded-md object-cover ring-1 ring-slate-200"
        />
      )}

      <dl className="min-w-[240px] flex-1 space-y-1.5 text-xs">
        {recipient.location_consent !== 'not_asked' && (
          <div className="flex gap-2">
            <dt className="w-24 shrink-0 text-slate-500">Location</dt>
            <dd className="text-slate-800">
              {recipient.location_summary}
              {recipient.latitude !== null && recipient.longitude !== null && (
                <a
                  href={`https://www.openstreetmap.org/?mlat=${recipient.latitude}&mlon=${recipient.longitude}#map=15/${recipient.latitude}/${recipient.longitude}`}
                  target="_blank"
                  rel="noreferrer"
                  className="ml-2 text-blue-700 underline"
                >
                  map
                </a>
              )}
            </dd>
          </div>
        )}

        {recipient.photo_consent !== 'not_asked' && (
          <div className="flex gap-2">
            <dt className="w-24 shrink-0 text-slate-500">Photograph</dt>
            <dd className="text-slate-800">{recipient.photo_summary}</dd>
          </div>
        )}

        <div className="flex gap-2">
          <dt className="w-24 shrink-0 text-slate-500">IP</dt>
          <dd className="font-mono text-slate-800">{recipient.last_ip ?? '—'}</dd>
        </div>
      </dl>
    </div>
  )
}
