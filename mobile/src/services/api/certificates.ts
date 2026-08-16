import {publicRequest} from '../../constants/api';
import {payload, resourceList} from './common';

export type Certificate = {
  id: string;
  publicId: string;
  courseId?: string;
  portfolioUrl: string;
  certificateUrl?: string;
  courseName: string;
  status: 'active' | 'pending' | 'revoked' | string;
};

type CertificateDto = {
  id?: unknown;
  certificate_id?: unknown;
  public_id?: unknown;
  portfolio_url?: unknown;
  certificate_url?: unknown;
  status?: unknown;
  course?: {id?: unknown; name?: unknown};
};

const mapCertificate = (item: CertificateDto): Certificate => ({
  id: String(item.certificate_id || item.id),
  publicId: String(item.public_id || item.certificate_id || item.id),
  courseId: item.course?.id ? String(item.course.id) : undefined,
  portfolioUrl: String(item.portfolio_url || ''),
  certificateUrl: item.certificate_url
    ? String(item.certificate_url)
    : undefined,
  courseName: String(item.course?.name || 'شهادة ركن'),
  status: String(item.status || 'active'),
});

export const getCertificates = async (): Promise<Certificate[]> => {
  const data = payload<CertificateDto[] | {data?: CertificateDto[]}>(
    await publicRequest.get('certificates'),
  );
  const list = resourceList<CertificateDto>(data);
  return list
    .filter(item => item.id !== null && item.id !== undefined)
    .map(mapCertificate);
};

export const issueCertificate = async (
  courseId: string,
): Promise<Certificate | null> => {
  const response = await publicRequest.post(`certificates/${courseId}/issue`);
  const data = payload<CertificateDto>(response);
  return data?.id || data?.certificate_id ? mapCertificate(data) : null;
};

export type ProductionCertificate = Certificate;
export const getProductionCertificates = getCertificates;
export const issueProductionCertificate = issueCertificate;
