// Mirrors App\Support\Money::format(): integer NANOdollars → "$0.046125", "$12.50".
// Never rounds: trailing zeros are trimmed, at least two decimals kept.
export const NANOS_PER_DOLLAR = 1_000_000_000;

export function formatNanos(nanos) {
    const value = BigInt(Math.round(Number(nanos)));
    const sign = value < 0n ? '-' : '';
    const abs = value < 0n ? -value : value;
    const whole = abs / 1_000_000_000n;
    let fraction = (abs % 1_000_000_000n).toString().padStart(9, '0').replace(/0+$/, '');
    fraction = fraction.padEnd(2, '0');

    return `${sign}$${whole.toLocaleString('en-US')}.${fraction}`;
}

// Unicode code points — matches PHP's mb_strlen() used by the server.
export const characterCount = (text) => [...text].length;
