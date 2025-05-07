module.exports = {
  // Thư mục chứa các file test (quy ước __tests__)
  roots: ['<rootDir>/js'], 
  
  // Các mẫu đường dẫn mà Jest sẽ bỏ qua
  testPathIgnorePatterns: [
    '<rootDir>/node_modules/',
    '<rootDir>/tests/',          // Bỏ qua thư mục Playwright tests
    '<rootDir>/tests-examples/', // Bỏ qua thư mục Playwright examples
  ],

  // Môi trường test (cho code chạy trên trình duyệt/DOM ảo)
  testEnvironment: 'jsdom',

  // Sử dụng Babel để chuyển đổi code ES Modules
  transform: {
    '^.+\\.js$': 'babel-jest',
  },

  // Cho phép Jest hiểu ES Modules
  moduleFileExtensions: ['js', 'json', 'node'],

  // THÊM MODULE NAME MAPPER
  moduleNameMapper: {
    '^https://unpkg.com/photoswipe@5/dist/photoswipe-lightbox.esm.js$': '<rootDir>/js/__mocks__/photoswipe-lightbox.mock.js',
    '^https://unpkg.com/photoswipe@5/dist/photoswipe.esm.js$': '<rootDir>/js/__mocks__/photoswipe.mock.js',
  },
}; 