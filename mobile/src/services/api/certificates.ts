import {publicRequest} from '../../constants/api';
import {
  isApiRecord,
  isResourceListPayload,
  payload,
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

const mapCertificate = (value: unknown): Certificate | null => {
  if (!isApiRecord(value)) return null;
  const item = value as CertificateDto;
  const id = String(item.certificate_id ?? item.id ?? '').trim();
  const publicId = String(
    item.public_id ?? item.certificate_id ?? item.id ?? '',
  ).trim();
  if (!id || !publicId) return null;
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
    portfolioUrl: String(item.verification_url || item.portfolio_url || ''),
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
  const data = payload<CertificateDto[] | {data?: CertificateDto[]}>(
    await publicRequest.get('certificates'),
  );
  if (!isResourceListPayload(data)) {
    throw new Error('CERTIFICATES_CONTRACT_INVALID');
  }
  const list = resourceList<CertificateDto>(data);
  return list.flatMap(item => {
    const certificate = mapCertificate(item);
    return certificate ? [certificate] : [];
  });
};

export const issueCertificate = async (
  courseId: string,
  holderName?: string,
): Promise<Certificate | null> => {
  const normalizedCourseId = String(courseId).trim();
  if (!/^\d+$/.test(normalizedCourseId)) {
    throw new Error('INVALID_CERTIFICATE_COURSE_ID');
  }
  const endpoint = `certificates/${normalizedCourseId}/issue`;
  const normalizedHolderName = holderName?.trim();
  const response = normalizedHolderName
    ? await publicRequest.post(endpoint, {holder_name: normalizedHolderName})
    : await publicRequest.post(endpoint);
  const data = payload<unknown>(response);
  return mapCertificate(data);
};

export type ProductionCertificate = Certificate;
export const getProductionCertificates = getCertificates;
export const issueProductionCertificate = issueCertificate;
