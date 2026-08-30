type ParsedUrl = URL & {
  protocol: string;
  hostname: string;
  username: string;
  password: string;
};

const allowedRoots = [
  'wa.me',
  'whatsapp.com',
  'instagram.com',
  'tiktok.com',
  'facebook.com',
  'fb.com',
  'youtube.com',
  'youtu.be',
  'rokn.app',
  'rokn.com',
] as const;

const isHostOrSubdomain = (hostname: string, root: string) =>
  hostname === root || hostname.endsWith(`.${root}`);

/** Treat every reward task destination as untrusted dashboard/API input. */
export const trustedExternalTaskUrl = (value: unknown) => {
  if (typeof value !== 'string' || !value.trim()) return null;
  try {
    const url = new URL(value.trim()) as unknown as ParsedUrl;
    const hostname = url.hostname.toLowerCase();
    if (
      url.protocol !== 'https:' ||
      url.username ||
      url.password ||
      !allowedRoots.some(root => isHostOrSubdomain(hostname, root))
    ) {
      return null;
    }
    return url.toString();
  } catch {
    return null;
  }
};
