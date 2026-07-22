export type Shape = string | { [key: string]: Shape } | [Shape];

export interface RawEntryAnalysis {
  structure: Shape | null;
  coordinatePaths: string[];
  coordinatesDetected: boolean;
}

function valueShape(value: unknown, depth = 0): Shape {
  if (value === null) {
    return "null";
  }
  if (Array.isArray(value)) {
    return value.length === 0 || depth >= 6 ? ["unknown"] : [valueShape(value[0], depth + 1)];
  }
  if (typeof value === "object") {
    if (depth >= 6) {
      return "object";
    }
    return Object.fromEntries(
      Object.entries(value as Record<string, unknown>).map(([key, child]) => [key, valueShape(child, depth + 1)])
    );
  }
  return typeof value;
}

function findCoordinatePaths(value: unknown, prefix = "", output = new Set<string>(), depth = 0): Set<string> {
  if (!value || typeof value !== "object" || depth > 10) {
    return output;
  }
  if (Array.isArray(value)) {
    value.slice(0, 3).forEach((child, index) => findCoordinatePaths(child, `${prefix}[${index}]`, output, depth + 1));
    return output;
  }

  for (const [key, child] of Object.entries(value as Record<string, unknown>)) {
    const childPath = prefix ? `${prefix}.${key}` : key;
    if (/^(?:lat|latitude|lon|lng|long|longitude|coordinates?|geometry|geom|geojson|geo_json|x|y)$/i.test(key)) {
      output.add(childPath);
    }
    findCoordinatePaths(child, childPath, output, depth + 1);
  }
  return output;
}

export function analyzeRawEntry(entry: unknown): RawEntryAnalysis {
  if (!entry || typeof entry !== "object") {
    return { structure: null, coordinatePaths: [], coordinatesDetected: false };
  }
  const coordinatePaths = [...findCoordinatePaths(entry)].sort();
  return {
    structure: valueShape(entry),
    coordinatePaths,
    coordinatesDetected: coordinatePaths.length > 0
  };
}
