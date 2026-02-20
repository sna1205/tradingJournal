export function asCurrency(value: number | string | null | undefined): string {
  const n = Number(value ?? 0)

  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(n)
}

export function asSignedCurrency(value: number | string | null | undefined): string {
  const n = Number(value ?? 0)
  const formatted = asCurrency(Math.abs(n))

  if (n > 0) {
    return `+${formatted}`
  }

  if (n < 0) {
    return `-${formatted}`
  }

  return formatted
}

export function asDate(value: string | null | undefined): string {
  if (!value) return '-'

  return new Date(value).toLocaleString('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  })
}
