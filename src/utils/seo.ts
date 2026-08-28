export interface SeoMetrics {
  score: number;
  grade: 'A' | 'B' | 'C' | 'D' | 'F';
  color: string;
  titleLength: number;
  titleStatus: 'optimal' | 'short' | 'long' | 'warning';
  titleRecommendation: string;
  descLength: number;
  descStatus: 'optimal' | 'short' | 'long' | 'missing';
  descRecommendation: string;
  handleStatus: 'optimal' | 'invalid' | 'missing';
  handleRecommendation: string;
  issues: string[];
}

export function evaluateSeo(title: string, metaDescription: string, handle: string): SeoMetrics {
  const t = (title || '').trim();
  const d = (metaDescription || '').trim();
  const h = (handle || '').trim();

  let score = 100;
  const issues: string[] = [];

  // Title validation
  let titleStatus: SeoMetrics['titleStatus'] = 'optimal';
  let titleRecommendation = 'Perfect title length for Google SERP (50-60 chars).';

  if (!t) {
    score -= 35;
    titleStatus = 'warning';
    titleRecommendation = 'Page title is completely missing.';
    issues.push('Missing Title Tag');
  } else if (t.length < 35) {
    score -= 15;
    titleStatus = 'short';
    titleRecommendation = `Too short (${t.length}/60 chars). Add brand or target keywords.`;
    issues.push(`Title is too short (${t.length} chars)`);
  } else if (t.length > 65) {
    score -= 10;
    titleStatus = 'long';
    titleRecommendation = `May be truncated on Google Search (${t.length}/60 chars).`;
    issues.push(`Title exceeds 65 chars (${t.length} chars)`);
  }

  if (/test\s*360/i.test(t) || /\[test\]/i.test(t) || /draft/i.test(t)) {
    score -= 15;
    titleStatus = 'warning';
    titleRecommendation = 'Remove internal testing brackets/codes before publishing.';
    issues.push('Title contains test markers');
  }

  // Meta Description validation
  let descStatus: SeoMetrics['descStatus'] = 'optimal';
  let descRecommendation = 'Optimal meta description length (120-160 chars).';

  if (!d) {
    score -= 35;
    descStatus = 'missing';
    descRecommendation = 'Meta description is missing. Google will auto-generate arbitrary snippets.';
    issues.push('Missing Meta Description');
  } else if (d.length < 90) {
    score -= 15;
    descStatus = 'short';
    descRecommendation = `Short description (${d.length}/160 chars). Add value proposition and call-to-action.`;
    issues.push(`Meta description too short (${d.length} chars)`);
  } else if (d.length > 165) {
    score -= 10;
    descStatus = 'long';
    descRecommendation = `Exceeds 160 characters (${d.length}/160). Will be truncated with '...' on mobile.`;
    issues.push(`Meta description exceeds 160 chars (${d.length} chars)`);
  }

  // Handle validation
  let handleStatus: SeoMetrics['handleStatus'] = 'optimal';
  let handleRecommendation = 'Clean URL slug format.';

  if (!h) {
    score -= 15;
    handleStatus = 'missing';
    handleRecommendation = 'URL handle is required for Shopify resource route.';
    issues.push('Missing URL Handle');
  } else if (/[A-Z_ ]/.test(h)) {
    score -= 10;
    handleStatus = 'invalid';
    handleRecommendation = 'Use lowercase letters and hyphens only (no uppercase or underscores).';
    issues.push('URL handle contains spaces or uppercase characters');
  }

  score = Math.max(10, Math.min(100, score));

  let grade: SeoMetrics['grade'] = 'A';
  let color = 'text-emerald-600 bg-emerald-50 border-emerald-300';

  if (score >= 90) {
    grade = 'A';
    color = 'text-emerald-700 bg-emerald-50 border-emerald-200';
  } else if (score >= 80) {
    grade = 'B';
    color = 'text-blue-700 bg-blue-50 border-blue-200';
  } else if (score >= 70) {
    grade = 'C';
    color = 'text-amber-700 bg-amber-50 border-amber-200';
  } else if (score >= 60) {
    grade = 'D';
    color = 'text-orange-700 bg-orange-50 border-orange-200';
  } else {
    grade = 'F';
    color = 'text-rose-700 bg-rose-50 border-rose-200';
  }

  return {
    score,
    grade,
    color,
    titleLength: t.length,
    titleStatus,
    titleRecommendation,
    descLength: d.length,
    descStatus,
    descRecommendation,
    handleStatus,
    handleRecommendation,
    issues,
  };
}
