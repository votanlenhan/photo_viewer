import { debounce } from '../utils';

jest.useFakeTimers(); // Sử dụng fake timers của Jest

describe('debounce', () => {
  let func;
  let debouncedFunc;

  beforeEach(() => {
    func = jest.fn();
    debouncedFunc = debounce(func, 100);
  });

  test('should execute function after wait time', () => {
    debouncedFunc();
    expect(func).not.toHaveBeenCalled(); // Chưa được gọi ngay

    jest.advanceTimersByTime(100); // Tua nhanh thời gian
    expect(func).toHaveBeenCalledTimes(1); // Được gọi 1 lần sau 100ms
  });

  test('should execute function only once if called multiple times within wait time', () => {
    debouncedFunc();
    debouncedFunc();
    debouncedFunc();

    jest.advanceTimersByTime(100);
    expect(func).toHaveBeenCalledTimes(1); // Chỉ được gọi 1 lần
  });

  test('should pass arguments to the original function', () => {
    debouncedFunc('hello', 123);
    jest.advanceTimersByTime(100);
    expect(func).toHaveBeenCalledWith('hello', 123);
  });

  test('should reset timeout if called again before wait time expires', () => {
    debouncedFunc(); // Call 1
    jest.advanceTimersByTime(50); // Tua 50ms
    expect(func).not.toHaveBeenCalled();

    debouncedFunc(); // Call 2 (reset timeout)
    jest.advanceTimersByTime(50); // Tua thêm 50ms (tổng 100ms từ Call 2)
    expect(func).not.toHaveBeenCalled(); // Vẫn chưa được gọi vì timeout đã reset

    jest.advanceTimersByTime(50); // Tua thêm 50ms (tổng 150ms từ Call 2, 100ms từ lúc func được gọi)
    expect(func).toHaveBeenCalledTimes(1); // Bây giờ mới được gọi
  });
}); 