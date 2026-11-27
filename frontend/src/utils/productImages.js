const PRODUCT_IMAGES = [
  '/products/leafy-greens.png',
  '/products/farm-harvest.png',
  '/products/hydroponic-lettuce.png',
  '/products/carrots.png',
  '/products/bell-peppers-basket.png',
  '/products/potatoes.png',
  '/products/water-spinach.png',
  '/products/bell-peppers-fresh.png',
];

export function assignProductImages(products) {
  const shuffledImages = [...PRODUCT_IMAGES];
  for (let index = shuffledImages.length - 1; index > 0; index -= 1) {
    const swapIndex = Math.floor(Math.random() * (index + 1));
    [shuffledImages[index], shuffledImages[swapIndex]] = [
      shuffledImages[swapIndex],
      shuffledImages[index],
    ];
  }

  return products.map((product, index) => {
    const imagePath = product.image_path;
    const image = imagePath
      ? (/^https?:\/\//i.test(imagePath) || imagePath.startsWith('/')
        ? imagePath
        : `/storage/${imagePath.replace(/^\/+/, '')}`)
      : shuffledImages[index % shuffledImages.length];

    return { ...product, display_image: image };
  });
}
