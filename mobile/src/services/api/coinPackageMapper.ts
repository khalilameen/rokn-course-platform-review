import {DISTRIBUTION_CHANNEL} from '../../constants/distribution';
import {learnerFacingText} from '../../utils/errorPayload';
import type {DemoCoinPackage} from '../demoExperience';
import {
  firstBoolean,
  nonNegativeNumber,
  resourceList,
} from './common';

export type CoinPackageDto = {
  id?: unknown;
  coins?: unknown;
  price?: unknown;
  direct_price?: unknown;
  name?: unknown;
  name_ar?: unknown;
  name_en?: unknown;
  recommended?: unknown;
  store_products?: {
    google?: unknown;
    apple?: unknown;
  };
  channels?: {
    direct?: unknown;
    google?: unknown;
    apple?: unknown;
  };
};

export const mapCoinPackages = (
  value: unknown,
  invalidContractCode: string,
): DemoCoinPackage[] => {
  const candidates = resourceList<CoinPackageDto>(value);
  const eligible = candidates.filter(item => {
    if (DISTRIBUTION_CHANNEL === 'direct') {
      return item.channels?.direct !== false && item.direct_price != null;
    }
    if (DISTRIBUTION_CHANNEL === 'play') {
      return item.channels?.google !== false && Boolean(item.store_products?.google);
    }
    return item.channels?.apple !== false && Boolean(item.store_products?.apple);
  });
  const seenIds = new Set<string>();
  const malformed = eligible.some(item => {
    const id = String(item.id ?? '').trim();
    const coins = nonNegativeNumber(item.coins);
    const price = nonNegativeNumber(
      DISTRIBUTION_CHANNEL === 'direct'
        ? item.direct_price ?? item.price
        : item.price,
    );
    if (
      !id ||
      seenIds.has(id) ||
      coins === null ||
      coins <= 0 ||
      price === null ||
      price <= 0
    ) {
      return true;
    }
    seenIds.add(id);
    return false;
  });
  if (malformed) {
    throw new Error(invalidContractCode);
  }
  const packages = eligible.map(item => {
    const id = String(item.id).trim();
    const coins = Number(item.coins);
    const price = Number(
      DISTRIBUTION_CHANNEL === 'direct'
        ? item.direct_price ?? item.price
        : item.price,
    );
    return {
      id,
      coins,
      price,
      label: learnerFacingText(
        item.name || item.name_ar || item.name_en,
        'باقة عملات ركن',
      ),
      recommended: firstBoolean(item.recommended) ?? false,
      storeProductIds: {
        google: item.store_products?.google
          ? String(item.store_products.google)
          : undefined,
        apple: item.store_products?.apple
          ? String(item.store_products.apple)
          : undefined,
      },
    };
  });

  return packages;
};
