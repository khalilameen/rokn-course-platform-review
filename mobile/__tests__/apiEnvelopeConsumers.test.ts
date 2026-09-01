import {publicRequest} from '../src/constants/api';
import {issueCertificate} from '../src/services/api/certificates';
import {getNotificationsPage} from '../src/services/api/notifications';

jest.mock('../src/constants/api', () => ({
  publicRequest: {
    get: jest.fn(),
    post: jest.fn(),
  },
}));

const mockedRequest = publicRequest as unknown as {
  get: jest.Mock;
  post: jest.Mock;
};

describe('API envelope consumers', () => {
  beforeEach(() => jest.clearAllMocks());

  it('keeps a successful pending certificate response as null', async () => {
    mockedRequest.post.mockResolvedValue({
      data: {
        status: 202,
        success: true,
        code: 'certificate_generating',
        data: null,
      },
    });

    await expect(issueCertificate('52')).resolves.toBeNull();
    expect(mockedRequest.post).toHaveBeenCalledWith('certificates/52/issue');
  });

  it('reads cursor metadata from the envelope and skips malformed rows', async () => {
    mockedRequest.get.mockResolvedValue({
      data: {
        status: 200,
        success: true,
        data: [
          {
            id: 9,
            notification_type: 'new_content',
            title_ar: 'محتوى جديد',
            message_ar: 'شاهد ما أضفناه',
            created_at: '2026-09-01T00:00:00Z',
            is_read: false,
          },
          null,
          {id: 'not-an-id'},
        ],
        pagination: {
          has_more_pages: true,
          next_cursor: 'next-page',
        },
      },
    });

    await expect(getNotificationsPage()).resolves.toMatchObject({
      page: 1,
      hasMore: true,
      nextCursor: 'next-page',
      notifications: [{id: '9'}],
    });
  });

  it('does not turn an HTML success body into an empty inbox', async () => {
    mockedRequest.get.mockResolvedValue({data: '<html>gateway page</html>'});

    await expect(getNotificationsPage()).rejects.toThrow(
      'NOTIFICATIONS_CONTRACT_INVALID',
    );
  });
});
