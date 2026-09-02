import {publicRequest} from '../../constants/api';
import {trustedCertificateVerificationUrl} from '../publicLinks';
import {
  accountScopedStorageKey,
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  getItem,
  saveItem,
} from '../../constants/helpers';
import {
  isApiRecord,
  isResourceListPayload,
  payload,
  responseEnvelope,
  resourceList,
} from './common';

export type Certificate = {
  id: string;
  publicId: string;
  courseId?: string;
  portfolioUrl: string;
  certificateUrl?: string;
  holderName: string;
  courseName: string;
  status: 'active' | 'pending' | 'revoked';
  verificationLevel: 'completion' | 'reviewed_project';
  verificationLabel: string;
};

const CERTIFICATES_CACHE_KEY = '@rokn/certificates-cache/v1';

type CertificatesCache = {
  version: 1;
  certificates: Certificate[];
};

const isCachedCertificate = (value: unknown): value is Certificate => {
  if (!isApiRecord(value)) return false;
  const status = String(value.status || '');
  const verificationLevel = String(value.verificationLevel || '');
  return (
    String(value.id || '').trim().length > 0 &&
    String(value.publicId || '').trim().length > 0 &&
    Boolean(
      trustedCertificateVerificationUrl(
        value.portfolioUrl,
        String(value.publicId || ''),
      ),
    ) &&
    ['active', 'pending', 'revoked'].includes(status) &&
    ['completion', 'reviewed_project'].includes(verificationLevel) &&
    typeof value.holderName === 'string' &&
    typeof value.courseName === 'string'
  );
};

export const getCachedCertificates = async (): Promise<Certificate[]> => {
  const boundary = await captureAccountSessionBoundary();
  const key = await accountScopedStorageKey(CERTIFICATES_CACHE_KEY, boundary);
  const cached = await getItem<Partial<CertificatesCache>>(key);
  assertAccountSessionBoundary(boundary);
  if (
    cached?.version !== 1 ||
    !Array.isArray(cached.certificates) ||
    !cached.certificates.every(isCachedCertificate)
  ) {
    return [];
  }
  return cached.certificates;
};

type CertificateDto = {
  id?: unknown;
  certificate_id?: unknown;
  public_id?: unknown;
  holder_name?: unknown;
  course_name?: unknown;
  portfolio_url?: unknown;
  verification_url?: unknown;
  certificate_url?: unknown;
  status?: unknown;
  verification_level?: unknown;
  verification_label?: unknown;
  course?: {id?: unknown; name?: unknown};
};

const mapCertificate = (value: unknown): Certificate => {
  if (!isApiRecord(value)) {
    throw new Error('CERTIFICATE_CONTRACT_INVALID');
  }
  const item = value as CertificateDto;
  const id = String(item.certificate_id ?? item.id ?? '').trim();
  const publicId = String(item.public_id ?? '').trim();
  if (!id || !publicId) {
    throw new Error('CERTIFICATE_CONTRACT_INVALID');
  }
  const verificationUrl = trustedCertificateVerificationUrl(
    item.verification_url ?? item.portfolio_url,
    publicId,
  );
  if (!verificationUrl) {
    throw new Error('CERTIFICATE_VERIFICATION_URL_INVALID');
  }
  const rawStatus = String(item.status || 'pending').toLowerCase();
  const status: Certificate['status'] = [
    'active',
    'pending',
    'revoked',
  ].includes(rawStatus)
    ? (rawStatus as Certificate['status'])
    : 'pending';
  const rawVerification = String(
    item.verification_level || 'completion',
  ).toLowerCase();
  const verificationLevel: Certificate['verificationLevel'] =
    rawVerification === 'reviewed_project' ? 'reviewed_project' : 'completion';
  const course = isApiRecord(item.course) ? item.course : undefined;
  return {
    id,
    publicId,
    courseId: course?.id ? String(course.id) : undefined,
    portfolioUrl: verificationUrl,
    certificateUrl:
      status === 'active' && item.certificate_url
        ? String(item.certificate_url)
        : undefined,
    holderName: String(item.holder_name || 'طالب ركن'),
    courseName: String(item.course_name || course?.name || 'شهادة ركن'),
    status,
    verificationLevel,
    verificationLabel: String(
      item.verification_label ||
        (verificationLevel === 'reviewed_project'
          ? 'إتمام الكورس ومراجعة المشروع'
          : 'إتمام الكورس'),
    ),
  };
};

export const getCertificates = async (): Promise<Certificate[]> => {
  const boundary = await captureAccountSessionBoundary();
  const data = payload<CertificateDto[] | {data?: CertificateDto[]}>(
    await publicRequest.get('certificates'),
  );
  assertAccountSessionBoundary(boundary);
  if (!isResourceListPayload(data)) {
    throw new Error('CERTIFICATES_CONTRACT_INVALID');
  }
  const list = resourceList<CertificateDto>(data);
  const certificates = list.map(mapCertificate);
  assertAccountSessionBoundary(boundary);
  const cacheKey = await accountScopedStorageKey(
    CERTIFICATES_CACHE_KEY,
    boundary,
  );
  try {
    await saveItem(cacheKey, {
      version: 1,
      certificates,
    } satisfies CertificatesCache);
  } catch {
    // A full or unavailable local store must not hide a valid certificate list.
    // The boundary still rejects a result owned by a previous account.
    assertAccountSessionBoundary(boundary);
  }
  assertAccountSessionBoundary(boundary);
  return certificates;
};

export const issueCertificate = async (
  courseId: string,
  holderName?: string,
): Promise<Certificate | null> => {
  const boundary = await captureAccountSessionBoundary();
  const normalizedCourseId = String(courseId).trim();
  if (!/^\d+$/.test(normalizedCourseId)) {
    throw new Error('INVALID_CERTIFICATE_COURSE_ID');
  }
  const endpoint = `certificates/${normalizedCourseId}/issue`;
  const normalizedHolderName = holderName?.trim();
  const response = normalizedHolderName
    ? await publicRequest.post(endpoint, {holder_name: normalizedHolderName})
    : await publicRequest.post(endpoint);
  assertAccountSessionBoundary(boundary);
  const envelope = responseEnvelope(response);
  const responseStatus = Number(
    (isApiRecord(response) ? response.status : undefined) ?? envelope.status ?? 0,
  );
  if (
    responseStatus === 202 &&
    envelope.success === true &&
    envelope.code === 'certificate_generating'
  ) {
    return null;
  }
  const data = payload<unknown>(response);
  const certificate = mapCertificate(data);
  assertAccountSessionBoundary(boundary);
  return certificate;
};

export type ProductionCertificate = Certificate;
export const getProductionCertificates = getCertificates;
export const issueProductionCertificate = issueCertificate;
