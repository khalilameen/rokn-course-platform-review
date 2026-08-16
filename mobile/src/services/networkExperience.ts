type NetworkFailureKind = 'offline' | 'timeout' | 'server' | 'unknown';

const asRecord = (value: unknown): Record<string, unknown> =>
  typeof value === 'object' && value !== null
    ? (value as Record<string, unknown>)
    : {};

export const networkFailureKind = (error: unknown): NetworkFailureKind => {
  const root = asRecord(error);
  const data = asRecord(root.data);
  const response = asRecord(root.response);
  const code = String(root.code || data.code || '').toUpperCase();
  const message = String(root.message || data.message || '').toLowerCase();
  const status = Number(root.status ?? response.status ?? 0);
  if (
    code === 'ECONNABORTED' ||
    code === 'ETIMEDOUT' ||
    message.includes('timeout')
  ) {
    return 'timeout';
  }
  if (!status && (code === 'ERR_NETWORK' || message.includes('network'))) {
    return 'offline';
  }
  if (status >= 500) return 'server';
  return 'unknown';
};

export const friendlyNetworkMessage = (error: unknown, subject = 'المحتوى') => {
  switch (networkFailureKind(error)) {
    case 'offline':
      return `الاتصال مقطوع الآن. تأكد من الإنترنت ثم حاول فتح ${subject} مرة أخرى.`;
    case 'timeout':
      return `الاتصال بطيء ولم يكتمل تحميل ${subject}. حاول مرة أخرى أو استخدم جودة أخف.`;
    case 'server':
      return `الخدمة مشغولة الآن ولم نفقد أيًا من بياناتك. حاول فتح ${subject} بعد لحظات.`;
    default:
      return `تعذّر تحميل ${subject} الآن. حاول مرة أخرى من نفس المكان.`;
  }
};
