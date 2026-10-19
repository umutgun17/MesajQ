<?php

namespace App\Contracts;

interface EcommercePlatformInterface
{
    /**
     * Get products with pagination.
     *
     * @return array{products: array, total: int}
     */
    public function getProducts(array $filters, int $page, int $pageSize): array;

    /**
     * Get total product count with optional filters.
     */
    public function getProductCount(array $filters): int;

    /**
     * Get all categories.
     */
    public function getCategories(): array;

    /**
     * Get all brands.
     */
    public function getBrands(): array;
}
