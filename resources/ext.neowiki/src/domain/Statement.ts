import { PropertyName } from '@/domain/PropertyDefinition';
import { Value } from '@/domain/Value';

export class Statement {

	public constructor(
		public readonly propertyName: PropertyName,
		public readonly propertyType: string,
		public readonly value: Value | undefined,
		public readonly propertyId?: string,
	) {
	}

	public hasValue(): boolean {
		return this.value !== undefined;
	}

	public withValue( value: Value | undefined ): Statement {
		return new Statement(
			this.propertyName,
			this.propertyType,
			value,
			this.propertyId,
		);
	}

	public withPropertyId( id: string | undefined ): Statement {
		return new Statement(
			this.propertyName,
			this.propertyType,
			this.value,
			id,
		);
	}

}
