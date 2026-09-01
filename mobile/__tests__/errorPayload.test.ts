import {learnerErrorMessage} from '../src/utils/errorPayload';

describe('learner error copy boundary', () => {
  it('does not expose an English API diagnostic to the learner', () => {
    expect(
      learnerErrorMessage(
        {data: {message: 'Internal server error'}},
        'حاول مرة أخرى',
      ),
    ).toBe('حاول مرة أخرى');
  });

  it('keeps a useful Arabic validation message', () => {
    expect(
      learnerErrorMessage(
        {data: {errors: {portfolio_slug: ['اسم المستخدم مستخدم بالفعل']}}},
        'حاول مرة أخرى',
      ),
    ).toBe('اسم المستخدم مستخدم بالفعل');
  });
});
