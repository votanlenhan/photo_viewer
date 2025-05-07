import {
  IMAGES_PER_PAGE,
  INITIAL_LOAD_LIMIT,
  STANDARD_LOAD_LIMIT,
  ACTIVE_ZIP_JOB_KEY,
  API_BASE_URL
} from '../config';

describe('Config Constants', () => {
  test('should export correct constant values', () => {
    expect(IMAGES_PER_PAGE).toBe(50);
    expect(INITIAL_LOAD_LIMIT).toBe(5);
    expect(STANDARD_LOAD_LIMIT).toBe(50);
    expect(ACTIVE_ZIP_JOB_KEY).toBe('activeZipJob');
    expect(API_BASE_URL).toBe('api.php');
  });
}); 